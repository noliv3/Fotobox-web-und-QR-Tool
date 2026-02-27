(function () {
  async function postForm(url, payload) {
    const body = new URLSearchParams(payload);
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body
    });
    return response.json();
  }

  const message = document.getElementById('apiMessage') || document.getElementById('orderMessage');

  const markForm = document.getElementById('markForm');
  if (markForm) {
    markForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(markForm);
      const result = await postForm('api_mark.php', {
        token: formData.get('token') || '',
        guest_name: formData.get('guest_name') || ''
      });
      message.textContent = result.error ? 'Fehler: ' + result.error : ('Gemerkte Fotos: ' + result.itemsCount);
    });
  }

  const printButton = document.querySelector('[data-print-token]');
  if (printButton) {
    printButton.addEventListener('click', async () => {
      const token = printButton.getAttribute('data-print-token');
      const apiKey = window.prompt('Print API Key eingeben');
      if (!apiKey) return;
      const result = await postForm('api_print.php', { token, api_key: apiKey });
      if (result.jobId) {
        message.textContent = 'Druckjob #' + result.jobId + ' angelegt';
      } else {
        message.textContent = 'Fehler: ' + (result.error || 'unbekannt');
      }
    });
  }

  document.querySelectorAll('[data-unmark-token]').forEach((button) => {
    button.addEventListener('click', async () => {
      const result = await postForm('api_unmark.php', { token: button.getAttribute('data-unmark-token') || '' });
      if (!result.error) {
        button.closest('.card')?.remove();
      }
      message.textContent = result.error ? 'Fehler: ' + result.error : ('Gemerkte Fotos: ' + result.itemsCount);
    });
  });

  const nameForm = document.getElementById('nameForm');
  if (nameForm) {
    nameForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const guestName = new FormData(nameForm).get('guest_name') || '';
      const result = await postForm('api_order_name.php', { guest_name: guestName });
      message.textContent = result.ok ? 'Name gespeichert.' : 'Fehler beim Speichern.';
    });
  }
})();
