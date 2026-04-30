/**
 * FlowSell AI — Chat Engine
 *
 * Drives the complete guided chat experience:
 *  - Loads the flow from WP REST API
 *  - Renders questions and options one at a time
 *  - Collects and routes answers
 *  - Fetches and displays WooCommerce product recommendations
 *  - Logs every session to the DB via REST API
 *
 * Globals injected via wp_localize_script:
 *  window.flowsellConfig { apiBase, nonce, cartUrl, primaryColor, widgetLabel, position }
 */

(function ($) {
  'use strict';

  /* ── Config ──────────────────────────────────────────────── */
  const CFG = window.flowsellConfig || {};
  const API = CFG.apiBase || '/wp-json/flowsell/v1';

  /* ── State ───────────────────────────────────────────────── */
  let state = {
    flow:        null,
    stepIndex:   0,
    stepHistory: [],   // ordered list of visited step IDs
    userAnswers: {},   // { step_id: chosen_answer }
    sessionId:   null,
    open:        false,
    finished:    false,
  };

  /* ── DOM Refs ─────────────────────────────────────────────── */
  const $widget    = $('#flowsell-widget');
  const $launcher  = $('#flowsell-launcher');
  const $window    = $('#flowsell-chat-window');
  const $messages  = $('#flowsell-messages');
  const $options   = $('#flowsell-options');
  const $products  = $('#flowsell-products');
  const $iconChat  = $launcher.find('.flowsell-icon-chat');
  const $iconClose = $launcher.find('.flowsell-icon-close');

  /* ── Utilities ───────────────────────────────────────────── */

  /**
   * Generate a unique session ID.
   */
  function generateSessionId() {
    return 'fs_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 9);
  }

  /**
   * Format the current time as HH:MM.
   */
  function timeNow() {
    const d = new Date();
    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
  }

  /**
   * Apply the primary colour from settings to CSS variables.
   */
  function applyPrimaryColor() {
    if (CFG.primaryColor) {
      document.documentElement.style.setProperty('--fs-primary', CFG.primaryColor);
    }
  }

  /* ── Message Rendering ───────────────────────────────────── */

  /**
   * Append a bot message bubble.
   *
   * @param {string} text
   * @param {number} delay  ms delay (for typing simulation)
   * @returns {Promise}
   */
  function botMessage(text, delay = 0) {
    return new Promise(resolve => {
      // Show typing indicator
      const $typing = $('<div class="flowsell-typing" aria-label="Advisor is typing" role="status">')
        .append('<span></span><span></span><span></span>');
      $messages.append($typing);
      scrollToBottom();

      setTimeout(() => {
        $typing.remove();

        const $bubble = $('<div class="flowsell-bubble flowsell-bubble-bot">')
          .text(text)
          .append($('<div class="flowsell-bubble-time">').text(timeNow()));

        $messages.append($bubble);
        scrollToBottom();
        resolve();
      }, delay || 700);
    });
  }

  /**
   * Append a user reply bubble.
   *
   * @param {string} text
   */
  function userMessage(text) {
    const $bubble = $('<div class="flowsell-bubble flowsell-bubble-user">')
      .text(text)
      .append($('<div class="flowsell-bubble-time">').text(timeNow()));

    $messages.append($bubble);
    scrollToBottom();
  }

  /**
   * Scroll messages area to the bottom.
   */
  function scrollToBottom() {
    $messages.animate({ scrollTop: $messages[0].scrollHeight }, 200);
  }

  /* ── Flow Engine ─────────────────────────────────────────── */

  /**
   * Load the active flow from the REST API.
   */
  async function loadFlow() {
    try {
      const resp = await $.getJSON(API + '/get-flow');
      if (resp.success && resp.flow) {
        state.flow = resp.flow;
        return true;
      }
    } catch (e) {
      console.warn('[FlowSell] Failed to load flow:', e);
    }
    return false;
  }

  /**
   * Return the step object for the given step ID.
   *
   * @param {string} stepId
   * @returns {object|null}
   */
  function getStep(stepId) {
    if (!state.flow || !state.flow.steps) return null;
    return state.flow.steps.find(s => s.id === stepId) || null;
  }

  /**
   * Return the current step object.
   */
  function currentStep() {
    if (!state.flow || !state.flow.steps) return null;
    return state.flow.steps[state.stepIndex] || null;
  }

  /**
   * Resolve the next step ID given the current step and the chosen option.
   *
   * @param {object} step
   * @param {string} answer
   * @returns {string|null}
   */
  function resolveNextStepId(step, answer) {
    if (step.next && step.next[answer] !== undefined) {
      return step.next[answer];
    }
    // Linear progression
    const idx = state.flow.steps.indexOf(step);
    const next = state.flow.steps[idx + 1];
    return next ? next.id : null;
  }

  /**
   * Start the conversation: show welcome message, then render first step.
   */
  async function startFlow() {
    if (!state.flow) return;

    state.stepIndex   = 0;
    state.stepHistory = [];
    state.userAnswers = {};
    state.finished    = false;
    state.sessionId   = generateSessionId();

    $messages.empty();
    $options.empty();
    $products.hide().empty();

    await renderStep(currentStep());
    logSession('in_progress');
  }

  /**
   * Render a single flow step: display bot question, then show option buttons.
   *
   * @param {object} step
   */
  async function renderStep(step) {
    if (!step) return;

    state.stepHistory.push(step.id);
    disableOptions();

    await botMessage(step.question, 700);
    renderOptions(step.options);
    enableOptions();
  }

  /**
   * Handle the user selecting an option.
   *
   * @param {string} answer  The chosen option label.
   */
  async function handleAnswer(answer) {
    const step = currentStep();
    if (!step || state.finished) return;

    disableOptions();
    clearOptions();
    userMessage(answer);

    // Record answer
    state.userAnswers[step.id] = answer;

    const nextStepId = resolveNextStepId(step, answer);

    if (nextStepId) {
      const nextIndex = state.flow.steps.findIndex(s => s.id === nextStepId);
      if (nextIndex !== -1) {
        state.stepIndex = nextIndex;
        logSession('in_progress');
        await renderStep(currentStep());
        return;
      }
    }

    // Terminal step — fetch products
    state.finished = true;
    logSession('recommended');
    await botMessage("Great choices! Let me find the perfect products for you… 🔍", 500);
    await fetchAndRenderProducts();
    renderRestartButton();
  }

  /* ── Product Rendering ───────────────────────────────────── */

  /**
   * POST to /get-products with answers and render results.
   */
  async function fetchAndRenderProducts() {
    try {
      const resp = await $.ajax({
        url:         API + '/get-products',
        method:      'POST',
        contentType: 'application/json',
        data:        JSON.stringify({ answers: state.userAnswers }),
        headers:     { 'X-WP-Nonce': CFG.nonce },
      });

      if (resp.success && resp.products && resp.products.length > 0) {
        renderProductCards(resp.products);
      } else {
        $('<div class="flowsell-no-products">').text(
          "Hmm, no products matched your preferences right now. Try browsing our store!"
        ).insertBefore($products);
        await botMessage("I couldn't find exact matches, but you can browse our full collection!", 400);
      }
    } catch (e) {
      console.warn('[FlowSell] Product fetch failed:', e);
      await botMessage("I had trouble loading products. Please visit our store directly.", 400);
    }
  }

  /**
   * Render product cards in the horizontal scroll area.
   *
   * @param {Array} products
   */
  function renderProductCards(products) {
    $products.empty();

    products.forEach(p => {
      const $card = $('<div class="flowsell-product-card">');

      // Image
      $card.append(
        $('<img class="flowsell-product-img" loading="lazy">')
          .attr('src', p.image)
          .attr('alt', p.name)
      );

      // Body
      const $body = $('<div class="flowsell-product-body">');
      $body.append($('<div class="flowsell-product-name">').text(p.name));
      $body.append($('<div class="flowsell-product-price">').html(p.price_html || p.price));

      if (p.rating && p.rating.count > 0) {
        $body.append(
          $('<div class="flowsell-product-rating">').text(
            '★'.repeat(Math.round(p.rating.average)) + ' (' + p.rating.count + ')'
          )
        );
      }

      if (p.short_desc) {
        $body.append($('<div class="flowsell-product-desc">').text(p.short_desc));
      }

      $card.append($body);

      // Actions
      const $actions = $('<div class="flowsell-product-actions">');

      if (p.in_stock) {
        $actions.append(
          $('<a class="flowsell-atc-btn" role="button">')
            .attr('href', p.add_to_cart)
            .text('🛒 Add to Cart')
        );
      } else {
        $actions.append($('<div class="flowsell-out-of-stock">').text('Out of Stock'));
      }

      $actions.append(
        $('<a class="flowsell-view-btn" target="_blank" rel="noopener">')
          .attr('href', p.permalink)
          .text('View Product')
      );

      $card.append($actions);
      $products.append($card);
    });

    $products.show();
    scrollToBottom();
  }

  /* ── Options UI ──────────────────────────────────────────── */

  /**
   * Render option buttons from an array of option labels.
   *
   * @param {Array<string>} options
   */
  function renderOptions(options) {
    $options.empty();
    if (!options || !options.length) return;

    options.forEach(opt => {
      const $btn = $('<button class="flowsell-option-btn" type="button">')
        .text(opt)
        .on('click', function () {
          handleAnswer(opt);
        });

      $options.append($btn);
    });
  }

  function clearOptions()   { $options.empty(); }
  function disableOptions() { $options.find('.flowsell-option-btn').prop('disabled', true); }
  function enableOptions()  { $options.find('.flowsell-option-btn').prop('disabled', false); }

  /**
   * Render the "Start Over" button at the end of the flow.
   */
  function renderRestartButton() {
    $options.empty();
    $('<button class="flowsell-restart-btn" type="button">')
      .text('🔄 Start Over')
      .on('click', startFlow)
      .appendTo($options);
  }

  /* ── Session Logging ─────────────────────────────────────── */

  /**
   * POST current session state to /log-session.
   *
   * @param {string} outcome
   */
  function logSession(outcome) {
    if (!state.sessionId) return;

    $.ajax({
      url:         API + '/log-session',
      method:      'POST',
      contentType: 'application/json',
      data:        JSON.stringify({
        session_id:   state.sessionId,
        flow_name:    state.flow ? state.flow.flow_name : 'unknown',
        step_history: state.stepHistory,
        user_answers: state.userAnswers,
        outcome:      outcome,
      }),
      headers: { 'X-WP-Nonce': CFG.nonce },
    }).fail(function (jqXHR) {
      console.warn('[FlowSell] Log session failed:', jqXHR.responseText);
    });
  }

  /**
   * Log a drop-off when the widget is closed mid-flow.
   */
  function logDropOff() {
    if (!state.finished && state.stepHistory.length > 0) {
      logSession('drop_off');
    }
  }

  /* ── Widget Open / Close ─────────────────────────────────── */

  async function openWidget() {
    state.open = true;
    $window.show();
    $launcher.attr('aria-expanded', 'true');
    $iconChat.hide();
    $iconClose.show();

    if (!state.flow) {
      const loaded = await loadFlow();
      if (!loaded) {
        await botMessage("Sorry, I'm having trouble loading the advisor. Please refresh the page.", 200);
        return;
      }
      await startFlow();
    }

    scrollToBottom();
  }

  function closeWidget() {
    state.open = false;
    logDropOff();
    $window.hide();
    $launcher.attr('aria-expanded', 'false');
    $iconChat.show();
    $iconClose.hide();
  }

  /* ── Add-to-Cart Intercept ───────────────────────────────── */

  /**
   * Handle "Add to Cart" clicks from product cards.
   * Logs a purchase outcome and optionally redirects.
   */
  $products.on('click', '.flowsell-atc-btn', function (e) {
    logSession('purchase');
    // Allow the default link navigation (WooCommerce ?add-to-cart=ID pattern)
  });

  /* ── Keyboard Accessibility ──────────────────────────────── */

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && state.open) {
      closeWidget();
    }
  });

  /* ── Event Bindings ──────────────────────────────────────── */

  $launcher.on('click', function () {
    state.open ? closeWidget() : openWidget();
  });

  $window.find('.flowsell-close-btn').on('click', closeWidget);

  /* ── Init ────────────────────────────────────────────────── */

  applyPrimaryColor();

  // Show a notification badge on the launcher after 3s to draw attention
  setTimeout(function () {
    if (!state.open) {
      const $badge = $('<span class="flowsell-badge" aria-hidden="true">');
      $launcher.css('position', 'relative').append($badge);
    }
  }, 3000);

})(jQuery);
