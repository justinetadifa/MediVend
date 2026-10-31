/* PHP owns prices, simulated coin events, and confirmed demo delivery counts.
   Real payments and physical hardware are never connected. */
let products = [];
let catalogStatus = 'loading';
let catalogError = '';
let catalogRequest = 0;
let catalogController = null;
const placeholderImage = 'assets/images/medicine-placeholder.svg';

// Decorative artwork preserves the welcome animation, including while the
// catalog is loading or unavailable. This is not an orderable product list.
const welcomeArtwork = [
  { image: 'assets/cetirizine.png', className: 'cetirizine' },
  { image: 'assets/diatabs.png', className: 'diatabs' },
  { image: 'assets/biogesic.png', className: 'biogesic' }
];

const localProductImages = new Map([
  ['demo-ceti', 'assets/cetirizine.png'],
  ['demo-lope', 'assets/diatabs.png'],
  ['demo-para', 'assets/biogesic.png'],
  ['cetirizine', 'assets/cetirizine.png'],
  ['loperamide', 'assets/diatabs.png'],
  ['diatabs', 'assets/diatabs.png'],
  ['paracetamol', 'assets/biogesic.png'],
  ['biogesic', 'assets/biogesic.png']
]);

const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
})[character]);

function productImages(row) {
  const fallback = localProductImages.get(row.sku.trim().toLowerCase()) || localProductImages.get(row.name.trim().toLowerCase()) || placeholderImage;
  if (!row.image_path?.trim()) return { image: fallback, fallback };
  try {
    const image = new URL(row.image_path.trim().replace(/\\/g, '/'), document.baseURI);
    if (!['http:', 'https:'].includes(image.protocol) || image.origin !== location.origin) return { image: fallback, fallback };
    return { image: image.href, fallback };
  } catch {
    return { image: fallback, fallback };
  }
}

function normalizeCatalog(payload) {
  if (!payload || !Array.isArray(payload.products)) throw new Error('invalid_catalog');
  const ids = new Set();
  return payload.products.map(row => {
    if (!row || !Number.isSafeInteger(row.id) || row.id <= 0 || ids.has(row.id)
      || typeof row.sku !== 'string' || typeof row.name !== 'string' || !row.name.trim()
      || (row.description !== null && typeof row.description !== 'string')
      || (row.image_path !== null && typeof row.image_path !== 'string')
      || !Number.isSafeInteger(row.price_centavos) || row.price_centavos < 0
      || !Number.isSafeInteger(row.stock_quantity) || row.stock_quantity < 0
      || typeof row.is_demo !== 'boolean') throw new Error('invalid_catalog');
    ids.add(row.id);
    return {
      id: String(row.id),
      name: row.name,
      description: row.description || '',
      price: row.price_centavos,
      stock: row.stock_quantity,
      form: row.is_demo ? 'Demo item' : 'Clinic item',
      color: 'mint',
      ...productImages(row)
    };
  });
}

function productPicture(product, width = 468, height = 254, decorative = false) {
  return `<img src="${escapeHtml(product.image)}" data-fallback="${escapeHtml(product.fallback)}" alt="${decorative ? '' : escapeHtml(product.name)}" width="${width}" height="${height}" draggable="false">`;
}

const iconPaths = {
  arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
  back: '<path d="M19 12H5m6-6-6 6 6 6"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  minus: '<path d="M5 12h14"/>',
  bag: '<path d="M5 8h14l1 12H4L5 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>',
  check: '<path d="m5 12 4 4L19 6"/>',
  shield: '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/>',
  spark: '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
};
const icon = name => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${iconPaths[name]}</svg>`;
const main = document.querySelector('#main');
const cart = new Map();
let screen = 'welcome';
let lastOrder = null;
let toastTimer;
let checkoutAttempt = null;
let checkoutBusy = false;
let checkoutUncertain = false;
let checkoutAcknowledged = false;
let checkoutError = '';
const checkoutStorageKey = 'medivend.pending-checkout.v1';

const money = amount => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount / 100);
const quantity = () => [...cart.values()].reduce((sum, count) => sum + count, 0);
const total = () => products.reduce((sum, product) => sum + product.price * (cart.get(product.id) || 0), 0);

function canonicalCheckoutItems(items) {
  if (!Array.isArray(items) || !items.length || items.length > 100) throw new Error('invalid_checkout_items');
  const ids = new Set();
  return items.map(item => {
    if (!item || !Number.isInteger(item.product_id) || item.product_id < 1 || item.product_id > 4294967295
      || !Number.isInteger(item.quantity) || item.quantity < 1 || item.quantity > 65535 || ids.has(item.product_id)) throw new Error('invalid_checkout_items');
    ids.add(item.product_id);
    return { product_id: item.product_id, quantity: item.quantity };
  }).sort((left, right) => left.product_id - right.product_id);
}

function keepCheckoutAttempt() {
  const bytes = crypto.getRandomValues(new Uint8Array(32));
  const key = [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
  const items = canonicalCheckoutItems([...cart].map(([id, count]) => ({ product_id: Number(id), quantity: count })));
  const catalog = products.filter(product => cart.has(product.id)).map(product => ({
    id: Number(product.id), sku: '', name: product.name, description: product.description,
    image_path: product.image, price_centavos: product.price, stock_quantity: product.stock,
    is_demo: product.form === 'Demo item'
  }));
  const attempt = { key, items, catalog };
  // Keep the immutable IDs, quantities and retry key before sending any request.
  // Cached catalog values are display-only and are never sent to checkout.php.
  sessionStorage.setItem(checkoutStorageKey, JSON.stringify(attempt));
  return attempt;
}

function forgetCheckoutAttempt() {
  checkoutAttempt = null;
  try { sessionStorage.removeItem(checkoutStorageKey); } catch { /* A replay remains safe if storage cannot be cleared. */ }
}

function restoreCheckoutAttempt() {
  try {
    const stored = sessionStorage.getItem(checkoutStorageKey);
    if (!stored) return;
    const attempt = JSON.parse(stored);
    if (typeof attempt.key !== 'string' || !/^[a-f0-9]{64}$/.test(attempt.key)) throw new Error('invalid_retry_key');
    const items = canonicalCheckoutItems(attempt.items);
    const cached = normalizeCatalog({ products: attempt.catalog });
    if (cached.length !== items.length || items.some(item => !cached.some(product => Number(product.id) === item.product_id))) throw new Error('invalid_cached_cart');
    checkoutAttempt = { key: attempt.key, items, catalog: attempt.catalog };
    products = cached;
    catalogStatus = 'ready';
    for (const item of items) cart.set(String(item.product_id), item.quantity);
    checkoutUncertain = true;
    checkoutAcknowledged = true;
    checkoutError = 'An earlier checkout is awaiting confirmation. Retry safely to check or save that same order.';
    screen = 'checkout';
  } catch {
    forgetCheckoutAttempt();
  }
}

function savedOrderFromResponse(payload, expectedItems) {
  const order = payload?.order;
  if (!order || typeof order.reference !== 'string' || !/^MV-[A-F0-9]{32}$/.test(order.reference)
    || !Number.isSafeInteger(order.total_centavos) || order.total_centavos < 0 || order.total_centavos > 4294967295
    || !Number.isSafeInteger(order.amount_paid_centavos) || order.amount_paid_centavos < 0 || order.amount_paid_centavos > order.total_centavos
    || !['pending', 'partial', 'paid', 'cancelled'].includes(order.payment_status)
    || !['not_started', 'in_progress', 'partial', 'dispensed', 'needs_review'].includes(order.dispensing_status)
    || !['pending', 'processing', 'completed', 'needs_review', 'cancelled'].includes(order.order_status) || order.is_demo !== true
    || typeof payload.state_token !== 'string' || !/^[a-f0-9]{64}$/.test(payload.state_token)
    || !Array.isArray(order.items)) throw new Error('invalid_saved_order');
  const items = canonicalCheckoutItems(order.items);
  if (JSON.stringify(items) !== JSON.stringify(expectedItems)) throw new Error('saved_cart_mismatch');
  let itemTotal = 0;
  const snapshots = order.items.map(item => {
    if (typeof item.product_name !== 'string' || !item.product_name.trim()
      || !Number.isSafeInteger(item.unit_price_centavos) || item.unit_price_centavos < 0
      || !Number.isSafeInteger(item.dispensed_quantity) || item.dispensed_quantity < 0 || item.dispensed_quantity > item.quantity) throw new Error('invalid_saved_item');
    itemTotal += item.unit_price_centavos * item.quantity;
    const cached = products.find(product => Number(product.id) === item.product_id);
    return {
      id: String(item.product_id), name: item.product_name, price: item.unit_price_centavos,
      qty: item.quantity, delivered: item.dispensed_quantity, color: 'mint',
      ...productImages({ sku: '', name: item.product_name, image_path: cached?.image || null })
    };
  });
  if (itemTotal !== order.total_centavos) throw new Error('invalid_saved_total');
  return { reference: order.reference, total: order.total_centavos, paid: order.amount_paid_centavos,
    paymentStatus: order.payment_status, dispensingStatus: order.dispensing_status, orderStatus: order.order_status,
    isDemo: order.is_demo, token: payload.state_token, items: snapshots };
}

function syncCheckoutControls() {
  const locked = checkoutBusy || checkoutUncertain || DemoFlow.locked;
  document.querySelector('#home').disabled = locked;
  const brand = document.querySelector('.brand');
  brand.setAttribute('aria-disabled', String(locked));
  if (locked) brand.tabIndex = -1;
  else brand.removeAttribute('tabindex');
}

async function saveOrder() {
  if (checkoutBusy || screen !== 'checkout' || !quantity()) return;
  if (!checkoutAttempt && (!checkoutAcknowledged || catalogStatus !== 'ready')) return;
  if (!checkoutAttempt) {
    try { checkoutAttempt = keepCheckoutAttempt(); }
    catch {
      checkoutError = 'The checkout request could not be kept for safe retries. Check browser storage and item quantities, then try again. Your cart is unchanged.';
      render();
      return;
    }
  }
  const attempt = checkoutAttempt;
  checkoutBusy = true;
  checkoutUncertain = true;
  checkoutError = '';
  render();
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 12000);
  try {
    const response = await fetch('api/checkout.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': attempt.key },
      body: JSON.stringify({ items: attempt.items }), cache: 'no-store', signal: controller.signal
    });
    const payload = await response.json();
    if (!response.ok) {
      if (response.status >= 400 && response.status < 500 && typeof payload?.error?.message === 'string') {
        forgetCheckoutAttempt();
        checkoutUncertain = false;
        checkoutError = payload.error.message;
        if (payload.error.code === 'idempotency_conflict') checkoutAcknowledged = false;
        return;
      }
      throw new Error('checkout_not_confirmed');
    }
    lastOrder = savedOrderFromResponse(payload, attempt.items);
    DemoFlow.attach(attempt.key);
    forgetCheckoutAttempt();
    checkoutUncertain = false;
    checkoutAcknowledged = false;
    cart.clear();
    go('pending');
  } catch {
    checkoutError = 'The order could not be confirmed. Your cart is kept. Retry safely to check or save the same order.';
  } finally {
    clearTimeout(timeout);
    checkoutBusy = false;
    if (screen === 'checkout') render();
    else syncCheckoutControls();
  }
}

function renderCatalog() {
  if (screen !== 'shop') return;
  const restoreFocus = main.contains(document.activeElement);
  render();
  if (restoreFocus) {
    const heading = main.querySelector('h1');
    heading.tabIndex = -1;
    heading.focus({ preventScroll: true });
  }
}

async function loadCatalog() {
  const request = ++catalogRequest;
  catalogController?.abort();
  const controller = new AbortController();
  catalogController = controller;
  catalogStatus = 'loading';
  catalogError = '';
  renderCatalog();
  const timeout = setTimeout(() => controller.abort(), 8000);
  try {
    if (location.protocol === 'file:') throw new Error('xampp_required');
    const response = await fetch('api/products.php', {
      headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal
    });
    if (!response.ok) throw new Error('catalog_unavailable');
    const nextProducts = normalizeCatalog(await response.json());
    if (request !== catalogRequest) return;
    const nextById = new Map(nextProducts.map(product => [product.id, product]));
    let adjusted = false;
    for (const [id, count] of cart) {
      const allowed = Math.min(count, nextById.get(id)?.stock || 0);
      if (allowed !== count) adjusted = true;
      if (allowed) cart.set(id, allowed);
      else cart.delete(id);
    }
    products = nextProducts;
    catalogStatus = products.length ? 'ready' : 'empty';
    renderCatalog();
    if (adjusted && screen === 'shop') announce('Your cart was updated to match current availability.');
  } catch (error) {
    if (request !== catalogRequest) return;
    catalogStatus = 'error';
    catalogError = error.message === 'xampp_required'
      ? 'Open MediVend through XAMPP at http://localhost/MediVend/ to load medicines.'
      : 'We could not connect to the medicine catalog. Please check the connection and try again.';
    renderCatalog();
  } finally {
    clearTimeout(timeout);
  }
}

function announce(message) {
  const toast = document.querySelector('#toast');
  toast.innerHTML = `${icon('check')}<span></span>`;
  toast.querySelector('span').textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
}

function go(next) {
  if (DemoFlow.protectsCredit && next !== 'pending') { DemoFlow.guardReset(); return; }
  if ((checkoutBusy || checkoutUncertain || DemoFlow.locked) && next !== 'pending') return;
  if (next === 'checkout' && !checkoutAttempt) {
    checkoutAcknowledged = false;
    checkoutError = '';
  }
  clearTimeout(toastTimer);
  document.querySelector('#toast').classList.remove('show');
  screen = next;
  render(true);
  window.scrollTo(0, 0);
  const title = main.querySelector('h1');
  if (title) {
    title.tabIndex = -1;
    title.focus({ preventScroll: true });
  }
}

function steps(current) {
  return `<div class="steps" aria-label="Step ${current} of 2: ${current === 1 ? 'Select medicines' : 'Review order'}">
    <span class="${current === 1 ? 'active' : 'done'}"><b>${current === 2 ? icon('check') : '1'}</b>Select</span>
    <i aria-hidden="true"></i><span class="${current === 2 ? 'active' : ''}"><b>2</b>Review</span>
  </div>`;
}

function welcome() {
  const medicineGroup = duplicate => `<div class="medicine-group"${duplicate ? ' aria-hidden="true" inert' : ''}>${welcomeArtwork.map(artwork => `<figure class="loop-medicine ${artwork.className}"><img src="${artwork.image}" data-fallback="${placeholderImage}" alt="" draggable="false"></figure>`).join('')}</div>`;
  return `<section class="welcome" aria-labelledby="welcome-title">
    <h1 id="welcome-title" class="welcome-title"><span>A LITTLE CARE.</span><span>A BETTER DAY.</span></h1>
    <p class="welcome-description"><span>Your clinic essentials, all in one place.</span><span>Choose your items. We'll keep it simple.</span></p>
    <div class="medicine-loop" role="img" aria-label="Cetirizine, Diatabs, and Biogesic medicine packaging">
      <div class="medicine-track">${medicineGroup(false)}${medicineGroup(true)}</div>
    </div>
    <button class="primary begin-button" data-action="begin">Get Started</button>
  </section>`;
}

function productCard(product, index) {
  const count = cart.get(product.id) || 0;
  const atLimit = count >= product.stock;
  const name = escapeHtml(product.name);
  return `<article class="product ${count ? 'in-cart' : ''}">
    <div class="product-art ${product.color}">
      <span class="product-number">${String(index + 1).padStart(2, '0')}</span>
      <span class="form-label">${product.form}</span>
      ${productPicture(product)}
      ${count ? `<span class="selected-badge">${icon('check')} ${count} in cart</span>` : ''}
    </div>
    <div class="product-info"><h2 title="${name}">${name}</h2><p class="product-desc" title="${escapeHtml(product.description)}">${escapeHtml(product.description)}</p></div>
    <div class="product-bottom"><span class="price">${money(product.price)}<small> / each</small></span><span class="stock">${product.stock - count} left to add</span></div>
    <button class="add" data-action="add" data-id="${product.id}" aria-label="${!product.stock ? `${name} is out of stock` : `Add ${name} to cart`}" ${atLimit ? 'disabled' : ''}>${icon(atLimit ? 'check' : 'plus')} ${!product.stock ? 'Out of stock' : atLimit ? 'Limit reached' : count ? 'Add another' : 'Add to cart'}</button>
  </article>`;
}

function cartMarkup() {
  const count = quantity();
  return `<aside class="cart" aria-labelledby="cart-title">
    <div class="cart-heading"><h2 id="cart-title">${icon('bag')} Your cart</h2><span class="count">${count}</span></div>
    <div class="cart-items">${count ? products.filter(product => cart.has(product.id)).map(product => `<div class="cart-row">
      <div class="cart-product"><div class="cart-thumb ${product.color}">${productPicture(product, 70, 45, true)}</div><div><h3>${escapeHtml(product.name)}</h3><p>${money(product.price)} each</p></div><strong>${money(product.price * cart.get(product.id))}</strong></div>
      <div class="row-controls"><button class="remove" data-action="remove" data-id="${product.id}" aria-label="Remove ${escapeHtml(product.name)}">Remove</button><div class="quantity"><button data-action="minus" data-id="${product.id}" aria-label="Decrease ${escapeHtml(product.name)}">${icon('minus')}</button><span aria-label="${cart.get(product.id)} ${escapeHtml(product.name)} in cart">${cart.get(product.id)}</span><button data-action="add" data-id="${product.id}" aria-label="Increase ${escapeHtml(product.name)}" ${catalogStatus !== 'ready' || cart.get(product.id) >= product.stock ? 'disabled' : ''}>${icon('plus')}</button></div></div>
    </div>`).join('') : `<div class="empty"><span class="empty-icon">${icon('bag')}<span>+</span></span><h3>A little empty here.</h3><p>Add something from the collection<br>to get started.</p></div>`}</div>
    <div class="cart-bottom"><div class="total"><div><span>Total</span><small>${count} item${count === 1 ? '' : 's'} in your cart</small></div><strong>${money(total())}</strong></div>
    <button class="primary checkout-button" data-action="checkout" ${!count || catalogStatus !== 'ready' ? 'disabled' : ''}>Review order ${icon('arrow')}</button>
    <p class="cart-note">${icon('shield')} Demo only. No real payment.</p></div>
  </aside>`;
}

function catalogStateMarkup() {
  if (catalogStatus === 'loading') {
    return `<div class="catalog-state" role="status"><span class="catalog-state-icon catalog-spinner" aria-hidden="true"></span><h2>Loading medicines…</h2><p>Getting the latest prices and availability.</p></div>`;
  }
  if (catalogStatus === 'error') {
    return `<div class="catalog-state" role="alert"><span class="catalog-state-icon" aria-hidden="true">!</span><h2>Unable to load medicines</h2><p>${escapeHtml(catalogError)}</p><button class="secondary" data-action="retry">Try again ${icon('arrow')}</button></div>`;
  }
  return `<div class="catalog-state" role="status"><span class="catalog-state-icon" aria-hidden="true">${icon('bag')}</span><h2>No medicines available</h2><p>There are no active products in the catalog right now. Please check back soon.</p><button class="secondary" data-action="retry">Refresh catalog ${icon('arrow')}</button></div>`;
}

function catalogPriceLabel() {
  if (catalogStatus !== 'ready') return '<span>Prices & availability</span>';
  const lowest = Math.min(...products.map(product => product.price));
  const allEqual = products.every(product => product.price === lowest);
  return `${allEqual ? '' : '<span>From</span>'}${money(lowest)} <span>each</span>`;
}

function shop() {
  return `<section class="shop">
    <div class="page-head"><div><p class="eyebrow"><span></span> The clinic collection</p><h1>A little care starts here.</h1><p class="sub">Choose from the clinic’s available medicines.</p></div>${steps(1)}</div>
    <div class="collection-bar"><div><span class="collection-dot"></span><span>All medicines</span><small>${catalogStatus === 'loading' ? '…' : String(catalogStatus === 'ready' ? products.length : 0).padStart(2, '0')}</small></div><span class="collection-price">${catalogPriceLabel()}</span></div>
    <div class="shop-layout"><div class="catalog"><div class="products" aria-busy="${catalogStatus === 'loading'}">${catalogStatus === 'ready' ? products.map(productCard).join('') : catalogStateMarkup()}</div><p class="catalog-note">${icon('shield')} Choose medicines with clinic staff guidance. Demo orders do not reserve stock.</p></div>${cartMarkup()}</div>
  </section>`;
}

function summaryLine(product, count) {
  return `<div class="summary-line"><div class="summary-product"><div class="summary-thumb ${product.color}">${productPicture(product, 80, 48)}</div><div><strong>${escapeHtml(product.name)}</strong><small>${money(product.price)} × ${count}</small></div></div><strong>${money(product.price * count)}</strong></div>`;
}

function checkout() {
  const locked = checkoutBusy || checkoutUncertain;
  return `<section class="checkout"><button class="back" data-action="shop" ${locked ? 'disabled' : ''}>${icon('back')} Back to collection</button>
    <div class="page-head"><div><p class="eyebrow">Almost there</p><h1>One last look.</h1><p class="sub">Review your items before saving your order.</p></div>${steps(2)}</div>
    <div class="checkout-grid"><div class="panel order-panel"><div class="panel-heading"><h2>Your selection</h2><span class="count">${quantity()} item${quantity() === 1 ? '' : 's'}</span></div>${products.filter(product => cart.has(product.id)).map(product => summaryLine(product, cart.get(product.id))).join('')}<div class="total"><span>Estimated total</span><strong>${money(total())}</strong></div><p class="notice">Final prices are confirmed when your order is saved.</p></div>
    <div class="panel checkout-panel ${checkoutError ? 'has-error' : ''}" aria-busy="${checkoutBusy}"><span class="panel-icon">${icon('shield')}</span><h2>Save a demo order.</h2><p class="sub">Test payment and delivery with simulation controls. No real coins or hardware are connected.</p>
    ${checkoutAttempt ? '' : `<label class="check-label"><input id="ack" type="checkbox" ${checkoutAcknowledged ? 'checked' : ''}><span>I understand this is a simulation. Confirmed demo delivery changes database stock only.</span></label>`}
    ${checkoutError ? `<p class="checkout-error" role="alert">${escapeHtml(checkoutError)}</p>` : ''}
    <button id="complete" class="primary" data-action="complete" ${checkoutBusy || (!checkoutAttempt && (!checkoutAcknowledged || catalogStatus !== 'ready')) ? 'disabled' : ''}>${checkoutBusy ? '<span class="button-spinner" aria-hidden="true"></span>Saving order…' : checkoutAttempt ? `Retry save ${icon('arrow')}` : `Save order ${icon('arrow')}`}</button>
    <p class="cart-note">${checkoutAttempt ? 'Retrying checks the same order, without creating a second one.' : 'You can go back and edit your items before saving.'}</p></div></div>
  </section>`;
}

function paymentPending() {
  return DemoFlow.markup();
}

function render(entering = false) {
  document.body.classList.toggle('welcome-screen', screen === 'welcome');
  main.innerHTML = screen === 'welcome' ? welcome() : screen === 'shop' ? shop() : screen === 'checkout' ? checkout() : paymentPending();
  syncCheckoutControls();
  if (entering && screen !== 'welcome') {
    main.firstElementChild.classList.add('screen-enter');
  }
}

function refreshCart(button, message) {
  const { action, id } = button.dataset;
  const region = button.closest('.cart') ? '.cart' : '.products';
  render();
  main.querySelector('.cart')?.classList.add('cart-updated');
  const replacement = main.querySelector(`${region} button[data-action="${action}"][data-id="${id}"]:not(:disabled)`);
  const fallback = main.querySelector(`.cart button[data-action="minus"][data-id="${id}"]`) || main.querySelector(`.products button[data-id="${id}"]:not(:disabled)`);
  (replacement || fallback)?.focus({ preventScroll: true });
  announce(message);
}

main.addEventListener('click', event => {
  const button = event.target.closest('button[data-action]');
  if (!button || button.disabled) return;
  const action = button.dataset.action;
  const product = products.find(item => item.id === button.dataset.id);

  if (action.startsWith('demo-')) { DemoFlow.action(button); return; }

  if ((checkoutBusy || checkoutUncertain || DemoFlow.locked) && action !== 'complete') return;

  if (action === 'begin' || action === 'shop') go('shop');
  if (action === 'retry') loadCatalog();
  if (action === 'add' && product && catalogStatus === 'ready') {
    const count = cart.get(product.id) || 0;
    if (count < product.stock) {
      cart.set(product.id, count + 1);
      refreshCart(button, `${product.name} added to cart`);
    }
  }
  if (action === 'minus' && product) {
    const count = cart.get(product.id) || 0;
    if (count > 1) cart.set(product.id, count - 1);
    else cart.delete(product.id);
    refreshCart(button, 'Cart updated');
  }
  if (action === 'remove' && product) {
    cart.delete(product.id);
    refreshCart(button, `${product.name} removed`);
  }
  if (action === 'checkout' && quantity() && catalogStatus === 'ready') go('checkout');
  if (action === 'complete') saveOrder();
  if (action === 'new') resetSession();
});

main.addEventListener('change', event => {
  if (event.target.id === 'ack') {
    checkoutAcknowledged = event.target.checked;
    document.querySelector('#complete').disabled = !checkoutAcknowledged || checkoutBusy || catalogStatus !== 'ready';
  }
});

function requestReset() {
  if (DemoFlow.guardReset()) return;
  if (checkoutBusy || checkoutUncertain || DemoFlow.locked) return;
  if (quantity()) document.querySelector('#reset-dialog').showModal();
  else resetSession();
}

function resetSession() {
  if (DemoFlow.guardReset()) return;
  if (checkoutBusy || checkoutUncertain || DemoFlow.locked) return;
  document.querySelector('#reset-dialog').close();
  forgetCheckoutAttempt();
  DemoFlow.clear();
  checkoutAcknowledged = false;
  checkoutError = '';
  cart.clear();
  lastOrder = null;
  go('welcome');
  loadCatalog();
}

document.querySelector('#home').addEventListener('click', resetSession);
document.querySelector('.brand').addEventListener('click', event => {
  event.preventDefault();
  requestReset();
});
document.querySelector('#keep').addEventListener('click', () => document.querySelector('#reset-dialog').close());
document.querySelector('#reset').addEventListener('click', resetSession);
document.querySelector('#staff-close').addEventListener('click', () => document.querySelector('#staff-dialog').close());

// Broken image_path values fall back once, including cart and review thumbnails.
main.addEventListener('error', event => {
  const image = event.target;
  if (!(image instanceof HTMLImageElement) || !image.dataset.fallback) return;
  const fallback = image.dataset.fallback;
  delete image.dataset.fallback;
  image.src = fallback;
}, true);

document.addEventListener('pointerdown', event => {
  const button = event.target.closest('.primary:not(:disabled), .secondary:not(:disabled), .add:not(:disabled)');
  if (!button) return;
  const bounds = button.getBoundingClientRect();
  const diameter = Math.max(bounds.width, bounds.height) * 2;
  const ripple = document.createElement('span');
  ripple.className = 'tap-ripple';
  ripple.setAttribute('aria-hidden', 'true');
  ripple.style.width = ripple.style.height = `${diameter}px`;
  ripple.style.left = `${event.clientX - bounds.left - diameter / 2}px`;
  ripple.style.top = `${event.clientY - bounds.top - diameter / 2}px`;
  ripple.addEventListener('animationend', () => ripple.remove(), { once: true });
  button.append(ripple);
});

restoreCheckoutAttempt();
const resumeDemoOrder = !checkoutAttempt && DemoFlow.restore();
render();
if (resumeDemoOrder) DemoFlow.refresh();
else if (!checkoutAttempt) loadCatalog();
