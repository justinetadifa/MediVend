/* Simulation only. PHP verifies every balance, delivery and stock change. */
const DemoFlow = (() => {
  const storageKey = 'medivend.current-demo-order.v1';
  let key = null, busy = false, blocked = false, uncertainDelivery = false, error = '', progress = '';
  function persist() { sessionStorage.setItem(storageKey, JSON.stringify({ key, uncertainDelivery })); }
  function attach(orderKey) { key = orderKey; blocked = false; uncertainDelivery = false; error = ''; persist(); }
  function clear() {
    key = null; busy = false; blocked = false; uncertainDelivery = false; error = '';
    try { sessionStorage.removeItem(storageKey); } catch { /* Server order remains saved. */ }
  }
  function restore() {
    try {
      const value = JSON.parse(sessionStorage.getItem(storageKey));
      if (!value || !/^[a-f0-9]{64}$/.test(value.key)) return false;
      key = value.key; uncertainDelivery = value.uncertainDelivery === true;
      blocked = true; screen = 'pending'; return true;
    } catch { return false; }
  }
  function apply(payload) {
    lastOrder = savedOrderFromResponse(payload, canonicalCheckoutItems(payload.order?.items));
    if (['completed', 'needs_review'].includes(lastOrder.orderStatus)) uncertainDelivery = false;
    persist();
  }
  async function request(endpoint, body = null) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch('api/' + endpoint + '.php', {
        method: body ? 'POST' : 'GET', cache: 'no-store', signal: controller.signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Demo-Order-Key': key },
        ...(body ? { body: JSON.stringify(body) } : {})
      });
      const payload = await response.json();
      if (payload.order) apply(payload);
      if (!response.ok) {
        error = payload.error?.message || 'Simulation unavailable. Refresh status.';
        blocked = !payload.order;
        return false;
      }
      if (!payload.order) throw new Error('missing_order');
      blocked = false; return true;
    } finally { clearTimeout(timer); }
  }
  async function refresh() {
    if (!key || busy) return;
    busy = true; progress = 'Checking saved status…'; error = ''; render();
    try {
      await request('demo-order');
      if (uncertainDelivery && lastOrder && !['completed', 'needs_review'].includes(lastOrder.orderStatus)) {
        error = 'Please contact clinic staff. A delivery result was uncertain. Stop with “No sensor confirmation”; no delivery event was resent.';
      }
    } catch { blocked = true; error = 'Saved status could not be checked. Please contact clinic staff if delivery was in progress. Refresh status; events are never automatically resent.'; }
    finally { busy = false; render(); }
  }
  async function event(kind, fields) {
    if (busy || blocked || !lastOrder?.isDemo || !key) return;
    const token = lastOrder.token;
    const wasUncertain = uncertainDelivery;
    if (kind === 'dispense') uncertainDelivery = true;
    try { persist(); } catch { error = 'Browser storage is unavailable. No event was sent.'; uncertainDelivery = wasUncertain; render(); return; }
    busy = true; progress = kind === 'dispense' ? 'Dispensing…' : 'Processing demo coin…'; error = ''; render();
    const showProcessing = new Promise(resolve => setTimeout(resolve, 400));
    try {
      const success = await request('demo-' + kind, { state_token: token, ...fields });
      if (success || !blocked) {
        // Read the response, never resend a stale event. Confirmed counters
        // replace local values after concurrent/duplicate request rejection.
        uncertainDelivery = wasUncertain;
        if (['completed', 'needs_review'].includes(lastOrder.orderStatus)) uncertainDelivery = false;
        persist();
      }
    } catch {
      blocked = true;
      error = kind === 'dispense'
        ? 'Please contact clinic staff. Delivery could not be confirmed. Refresh status to check saved records; no delivery event will be retried.'
        : 'Coin result could not be confirmed. Refresh status before inserting another demo coin. The event will not be resent.';
    } finally { await showProcessing; busy = false; render(); }
  }
  function action(button) {
    const action = button.dataset.action;
    if (action === 'demo-refresh') { refresh(); return; }
    if (action === 'demo-coin') event('payment', { coin_centavos: Number(button.dataset.coin) });
    if (action === 'demo-start') event('dispense', { action: 'start', product_id: null });
    if (action === 'demo-delivered' && !uncertainDelivery) event('dispense', { action: 'delivered', product_id: Number(button.dataset.id) });
    if (action === 'demo-jam') event('dispense', { action: 'jam', product_id: null });
    if (action === 'demo-no-confirmation') event('dispense', { action: 'no_confirmation', product_id: null });
  }
  function markup() {
    if (!lastOrder) return `<section class="demo-flow"><div class="catalog-state" role="status"><span class="catalog-state-icon ${busy ? 'catalog-spinner' : ''}">${busy ? '' : '!'}</span><h2>${busy ? 'Loading saved order…' : 'Unable to load order'}</h2><p>${escapeHtml(error || 'Checking your current demo order.')}</p><button class="secondary" data-action="demo-refresh" ${busy ? 'disabled' : ''}>Refresh status</button></div></section>`;
    const order = lastOrder;
    const review = order.orderStatus === 'needs_review', completed = order.orderStatus === 'completed';
    const paid = order.paymentStatus === 'paid', running = order.orderStatus === 'processing';
    const remaining = order.total - order.paid;
    const delivered = order.items.reduce((sum, item) => sum + item.delivered, 0);
    const count = order.items.reduce((sum, item) => sum + item.qty, 0);
    const disabled = busy || blocked || !order.isDemo;
    const heading = review ? 'Please contact clinic staff' : completed ? 'Demo delivery complete.' : running ? 'Dispensing…' : paid ? 'Demo payment complete.' : 'Demo payment';
    const coinButtons = [100, 500, 1000].map(coin => `<button class="secondary demo-coin" data-action="demo-coin" data-coin="${coin}" ${disabled || coin > remaining ? 'disabled' : ''}>Demo insert ${money(coin).replace('.00', '')}</button>`).join('');
    let controls = '';
    if (review) controls = '<div class="staff-message" role="alert"><strong>Please contact clinic staff.</strong><p>The simulation has stopped. Confirmed deliveries are preserved. No automatic retry or refund has occurred.</p></div>';
    else if (completed) controls = '<div class="demo-complete">' + icon('check') + '<strong>Every ordered unit is confirmed.</strong><p>Simulation only. No physical hardware was activated.</p></div>';
    else if (!paid) controls = `<p class="demo-help">Simulation coins only · No change provided.</p><div class="demo-coins">${coinButtons}</div><p class="notice">A coin that exceeds the remaining balance is rejected.</p>`;
    else if (!running) controls = uncertainDelivery
      ? `<p class="demo-help">Please contact clinic staff. The previous delivery request was uncertain.</p><button class="secondary" data-action="demo-no-confirmation" ${disabled ? 'disabled' : ''}>Simulate no sensor confirmation / stop</button>`
      : `<p class="demo-help">Demo payment complete. Recheck stock and start the delivery simulation.</p><button class="primary" data-action="demo-start" ${disabled ? 'disabled' : ''}>Simulate dispensing ${icon('arrow')}</button>`;
    else controls = `<p class="demo-help">Simulation: confirm one delivered unit, or stop with a test fault.</p><div class="delivery-buttons">${order.items.filter(item => item.delivered < item.qty).map(item => `<button class="secondary" data-action="demo-delivered" data-id="${item.id}" ${disabled || uncertainDelivery ? 'disabled' : ''}>Simulate 1 delivered · ${escapeHtml(item.name)}</button>`).join('')}</div><div class="demo-faults"><button class="secondary" data-action="demo-jam" ${disabled ? 'disabled' : ''}>Simulate jam</button><button class="secondary" data-action="demo-no-confirmation" ${disabled ? 'disabled' : ''}>Simulate no sensor confirmation</button></div>`;
    return `<section class="demo-flow"><div class="page-head"><div><p class="eyebrow">Demo simulation only</p><h1 class="demo-heading ${review ? 'review-heading' : ''}">${heading}</h1><p class="sub">No real coins, payments, motors, or sensors are connected.</p></div><button class="back" data-action="demo-refresh" ${busy ? 'disabled' : ''}>Refresh status ${icon('clock')}</button></div><div class="checkout-grid demo-grid"><div class="panel demo-summary"><div class="order-reference"><span>Saved reference</span><strong>${escapeHtml(order.reference)}</strong></div><div class="demo-items">${order.items.map(item => `<div class="demo-delivery-row">${summaryLine(item, item.qty)}<p>${item.delivered} / ${item.qty} units confirmed</p></div>`).join('')}</div><div class="payment-amounts"><div><span>Total due</span><strong>${money(order.total)}</strong></div><div><span>Amount received</span><strong>${money(order.paid)}</strong></div><div><span>Remaining balance</span><strong>${money(remaining)}</strong></div></div><div class="order-statuses"><span>${paid ? 'Demo paid' : order.paymentStatus === 'partial' ? 'Partial demo payment' : 'Payment pending'}</span><span>${review ? 'Needs review' : completed ? 'Delivery confirmed' : running ? `${delivered} / ${count} confirmed` : 'Dispensing not started'}</span></div></div><div class="panel demo-controls" aria-busy="${busy}">${busy ? `<div class="demo-processing" role="status"><span class="button-spinner"></span>${progress}</div>` : ''}${error ? `<p class="checkout-error" role="alert">${escapeHtml(error)}</p>` : ''}${controls}<p class="notice demo-stock-note">Only a confirmed simulated unit decreases database demo stock.</p></div></div><div class="demo-bottom"><button class="secondary" data-action="new" ${busy || blocked ? 'disabled' : ''}>Start a new session</button><p class="notice">Saved orders and confirmed delivery records remain in the database.</p></div></section>`;
  }
  return { attach, clear, restore, refresh, action, markup, get locked() { return busy || blocked; } };
})();
