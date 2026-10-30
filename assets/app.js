/* Offline clinic demo. A future backend must validate real stock, payment,
   and dispensing. Prices below are in centavos. */
const products = [
  { id: 'diatabs', name: 'Diatabs', description: 'Loperamide', form: 'Capsule', price: 1000, stock: 10, image: 'assets/diatabs.png', color: 'mint' },
  { id: 'biogesic', name: 'Biogesic', description: 'Paracetamol', form: 'Tablet', price: 1000, stock: 10, image: 'assets/biogesic.png', color: 'blue' },
  { id: 'cetirizine', name: 'Cetirizine', description: 'Cetirizine dihydrochloride', form: 'Tablet', price: 1000, stock: 10, image: 'assets/cetirizine.png', color: 'lavender' }
];

const iconPaths = {
  arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
  back: '<path d="M19 12H5m6-6-6 6 6 6"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  minus: '<path d="M5 12h14"/>',
  bag: '<path d="M5 8h14l1 12H4L5 8Z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/>',
  check: '<path d="m5 12 4 4L19 6"/>',
  shield: '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/>',
  spark: '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z"/>'
};
const icon = name => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${iconPaths[name]}</svg>`;
const main = document.querySelector('#main');
const cart = new Map();
let screen = 'welcome';
let lastOrder = null;
let toastTimer;

const money = amount => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount / 100);
const quantity = () => [...cart.values()].reduce((sum, count) => sum + count, 0);
const total = () => products.reduce((sum, product) => sum + product.price * (cart.get(product.id) || 0), 0);

function announce(message) {
  const toast = document.querySelector('#toast');
  toast.innerHTML = `${icon('check')}<span></span>`;
  toast.querySelector('span').textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
}

function go(next) {
  clearTimeout(toastTimer);
  document.querySelector('#toast').classList.remove('show');
  screen = next;
  render();
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
  const collection = [products[2], products[0], products[1]];
  const medicineGroup = duplicate => `<div class="medicine-group"${duplicate ? ' aria-hidden="true" inert' : ''}>${collection.map(product => `<figure class="loop-medicine ${product.id}"><img src="${product.image}" alt="" draggable="false"></figure>`).join('')}</div>`;
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
  return `<article class="product ${count ? 'in-cart' : ''}">
    <div class="product-art ${product.color}">
      <span class="product-number">0${index + 1}</span>
      <span class="form-label">${product.form}</span>
      <img src="${product.image}" alt="${product.name} packaging" width="468" height="254" draggable="false">
      ${count ? `<span class="selected-badge">${icon('check')} ${count} in cart</span>` : ''}
    </div>
    <div class="product-info"><h2>${product.name}</h2><p class="product-desc">${product.description}</p></div>
    <div class="product-bottom"><span class="price">${money(product.price)}<small> / each</small></span><span class="stock">${product.stock - count} left to add</span></div>
    <button class="add" data-action="add" data-id="${product.id}" aria-label="Add ${product.name} to cart" ${atLimit ? 'disabled' : ''}>${icon(atLimit ? 'check' : 'plus')} ${atLimit ? 'Limit reached' : count ? 'Add another' : 'Add to cart'}</button>
  </article>`;
}

function cartMarkup() {
  const count = quantity();
  return `<aside class="cart" aria-labelledby="cart-title">
    <div class="cart-heading"><h2 id="cart-title">${icon('bag')} Your cart</h2><span class="count">${count}</span></div>
    <div class="cart-items">${count ? products.filter(product => cart.has(product.id)).map(product => `<div class="cart-row">
      <div class="cart-product"><div class="cart-thumb ${product.color}"><img src="${product.image}" alt="" width="70" height="45"></div><div><h3>${product.name}</h3><p>${money(product.price)} each</p></div><strong>${money(product.price * cart.get(product.id))}</strong></div>
      <div class="row-controls"><button class="remove" data-action="remove" data-id="${product.id}" aria-label="Remove ${product.name}">Remove</button><div class="quantity"><button data-action="minus" data-id="${product.id}" aria-label="Decrease ${product.name}">${icon('minus')}</button><span aria-label="${cart.get(product.id)} ${product.name} in cart">${cart.get(product.id)}</span><button data-action="add" data-id="${product.id}" aria-label="Increase ${product.name}" ${cart.get(product.id) >= product.stock ? 'disabled' : ''}>${icon('plus')}</button></div></div>
    </div>`).join('') : `<div class="empty"><span class="empty-icon">${icon('bag')}<span>+</span></span><h3>A little empty here.</h3><p>Add something from the collection<br>to get started.</p></div>`}</div>
    <div class="cart-bottom"><div class="total"><div><span>Total</span><small>${count} item${count === 1 ? '' : 's'} in your cart</small></div><strong>${money(total())}</strong></div>
    <button class="primary checkout-button" data-action="checkout" ${!count ? 'disabled' : ''}>Review order ${icon('arrow')}</button>
    <p class="cart-note">${icon('shield')} Demo only. No payment required.</p></div>
  </aside>`;
}

function shop() {
  return `<section class="shop">
    <div class="page-head"><div><p class="eyebrow"><span></span> The clinic collection</p><h1>A little care starts here.</h1><p class="sub">Three everyday essentials. One simple price.</p></div>${steps(1)}</div>
    <div class="collection-bar"><div><span class="collection-dot"></span><span>All medicines</span><small>03</small></div><span class="collection-price">₱10 <span>each</span></span></div>
    <div class="shop-layout"><div class="catalog"><div class="products">${products.map(productCard).join('')}</div><p class="catalog-note">${icon('shield')} Sample prices & availability. Choose medicines with guidance from clinic staff.</p></div>${cartMarkup()}</div>
  </section>`;
}

function summaryLine(product, count) {
  return `<div class="summary-line"><div class="summary-product"><div class="summary-thumb ${product.color}"><img src="${product.image}" alt="${product.name} packaging" width="80" height="48"></div><div><strong>${product.name}</strong><small>${money(product.price)} × ${count}</small></div></div><strong>${money(product.price * count)}</strong></div>`;
}

function checkout() {
  return `<section class="checkout"><button class="back" data-action="shop">${icon('back')} Back to collection</button>
    <div class="page-head"><div><p class="eyebrow">Almost there</p><h1>One last look.</h1><p class="sub">A quick review before you finish.</p></div>${steps(2)}</div>
    <div class="checkout-grid"><div class="panel order-panel"><div class="panel-heading"><h2>Your selection</h2><span class="count">${quantity()} item${quantity() === 1 ? '' : 's'}</span></div>${products.filter(product => cart.has(product.id)).map(product => summaryLine(product, cart.get(product.id))).join('')}<div class="total"><span>Order total</span><strong>${money(total())}</strong></div><p class="notice">All items are ₱10 each. Prices are for this demo.</p></div>
    <div class="panel checkout-panel"><span class="panel-icon">${icon('shield')}</span><h2>A simple demo checkout.</h2><p class="sub">No coins, payment, or dispensing. Just a preview of your MediVend experience.</p><label class="check-label"><input id="ack" type="checkbox"><span>I understand this is a demo and medicine use requires clinic supervision.</span></label><button id="complete" class="primary" data-action="complete" disabled>Complete demo order ${icon('arrow')}</button><p class="cart-note">You can go back and edit your items anytime.</p></div></div>
  </section>`;
}

function success() {
  return `<section class="success"><div class="success-intro"><div class="success-icon">${icon('check')}</div><p class="eyebrow">Demo complete</p><h1>A little care, all set.</h1><p class="sub">Your sample order is complete.<br>No payment was taken and no medicine was dispensed.</p></div><div class="panel"><div class="order-reference"><span>Demo order</span><span>${lastOrder.id}</span></div>${lastOrder.items.map(product => summaryLine(product, product.qty)).join('')}<div class="total"><span>Sample total</span><strong>${money(lastOrder.total)}</strong></div></div><button class="primary" data-action="new">Start a new session ${icon('arrow')}</button></section>`;
}

function render() {
  document.body.classList.toggle('welcome-screen', screen === 'welcome');
  main.innerHTML = screen === 'welcome' ? welcome() : screen === 'shop' ? shop() : screen === 'checkout' ? checkout() : success();
  if (screen === 'welcome') {
    const track = main.querySelector('.medicine-track');
    // Decode both identical groups before moving them, avoiding an empty first lap.
    Promise.all([...track.querySelectorAll('img')].map(image => image.decode().catch(() => {}))).then(() => {
      if (track.isConnected) track.classList.add('is-ready');
    });
  }
}

function refreshCart(button, message) {
  const { action, id } = button.dataset;
  const region = button.closest('.cart') ? '.cart' : '.products';
  render();
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

  if (action === 'begin' || action === 'shop') go('shop');
  if (action === 'add' && product) {
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
  if (action === 'checkout' && quantity()) go('checkout');
  if (action === 'complete' && screen === 'checkout' && quantity() && document.querySelector('#ack')?.checked) {
    lastOrder = {
      id: 'MV-' + Date.now().toString(36).toUpperCase(),
      total: total(),
      items: products.filter(item => cart.has(item.id)).map(item => ({ ...item, qty: cart.get(item.id) }))
    };
    cart.clear();
    go('success');
  }
  if (action === 'new') {
    lastOrder = null;
    cart.clear();
    go('welcome');
  }
});

main.addEventListener('change', event => {
  if (event.target.id === 'ack') document.querySelector('#complete').disabled = !event.target.checked;
});

function requestReset() {
  if (quantity()) document.querySelector('#reset-dialog').showModal();
  else {
    lastOrder = null;
    go('welcome');
  }
}

function resetSession() {
  document.querySelector('#reset-dialog').close();
  cart.clear();
  lastOrder = null;
  go('welcome');
}

document.querySelector('#home').addEventListener('click', resetSession);
document.querySelector('.brand').addEventListener('click', event => {
  event.preventDefault();
  requestReset();
});
document.querySelector('#keep').addEventListener('click', () => document.querySelector('#reset-dialog').close());
document.querySelector('#reset').addEventListener('click', resetSession);

render();
