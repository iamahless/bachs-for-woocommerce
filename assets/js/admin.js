(() => {
  const currencies = ['NGN', 'GHS', 'KES', 'MWK', 'RWF', 'TZS', 'UGX', 'XAF', 'XOF', 'ZMW'];
  const root = document.querySelector('#bachs-locked-prices');
  const add = document.querySelector('.bachs-add-price');
  if (root && add) {
    add.addEventListener('click', () => {
      const name = root.dataset.name;
      const options = currencies.map((currency) => `<option value="${currency}">${currency}</option>`).join('');
      root.insertAdjacentHTML('beforeend', `<p class="bachs-price-row"><select name="${name}[currency][]"><option value="">Currency</option>${options}</select> <input type="text" name="${name}[amount][]" value="" placeholder="0.00"> <button type="button" class="button-link-delete bachs-remove-price">Remove</button></p>`);
    });
    root.addEventListener('click', (event) => {
      if (event.target.closest('.bachs-remove-price')) event.target.closest('.bachs-price-row').remove();
    });
  }
  document.querySelector('#bachs-copy-webhook')?.addEventListener('click', async (event) => {
    const text = document.querySelector('#bachs-webhook-url')?.textContent || '';
    try { await navigator.clipboard.writeText(text); event.currentTarget.textContent = 'Copied'; } catch (_) { window.prompt('Copy webhook URL:', text); }
  });
})();
