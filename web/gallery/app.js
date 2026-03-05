(() => {
  'use strict';

  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  function postHeart(photoId) {
    const body = new URLSearchParams({ id: photoId });
    return fetch('/gallery/api_heart.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': csrfToken
      },
      body,
      credentials: 'same-origin'
    }).then((response) => {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    });
  }

  document.querySelectorAll('[data-heart-button]').forEach((button) => {
    button.addEventListener('click', () => {
      const photoId = button.getAttribute('data-photo-id');
      if (!photoId) return;

      postHeart(photoId).then((payload) => {
        if (!payload || payload.ok !== true) return;
        const countEl = button.querySelector('[data-heart-count]');
        if (countEl) {
          countEl.textContent = String(payload.total || 0);
        }
        button.classList.add('heart-pop');
        window.setTimeout(() => button.classList.remove('heart-pop'), 180);
      }).catch(() => {
        // bewusst still: Monitoransicht bleibt read-only nutzbar
      });
    });
  });
})();
