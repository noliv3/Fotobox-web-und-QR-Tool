(() => {
  'use strict';

  const toastRoot = document.getElementById('toast-root');
  let longPressFired = false;
  let pressTimer = null;
  let pressStart = null;
  let pressTarget = null;

  function postForm(url, payload) {
    const csrfMeta = document.querySelector('meta[name=\"csrf-token\"]');
    const csrfToken = csrfMeta ? (csrfMeta.getAttribute('content') || '').trim() : '';
    const body = new URLSearchParams(payload);
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': csrfToken
      },
      body,
      credentials: 'same-origin'
    }).then((r) => {
      if (!r.ok) {
        throw new Error('HTTP ' + r.status);
      }
      return r.json();
    }).catch(() => ({ ok: false }));
  }

  function showToast(message, options = {}) {
    if (!toastRoot) return;
    toastRoot.innerHTML = '';

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');

    const text = document.createElement('span');
    text.textContent = message;
    toast.appendChild(text);

    if (options.actionText && typeof options.actionCallback === 'function') {
      const action = document.createElement('button');
      action.type = 'button';
      action.className = 'toast-action';
      action.textContent = options.actionText;
      action.addEventListener('click', () => {
        options.actionCallback();
      });
      toast.appendChild(action);
    }

    toastRoot.appendChild(toast);
    window.setTimeout(() => {
      toast.classList.add('is-hiding');
      window.setTimeout(() => {
        if (toast.parentNode === toastRoot) toastRoot.removeChild(toast);
      }, 200);
    }, 1200);
  }

  function setFavState(el, isFav) {
    el.classList.toggle('is-fav', isFav);
  }

  function toggleFavById(photoId, done) {
    postForm('/mobile/api_mark.php', { action: 'toggle', id: photoId }).then((res) => {
      if (!res || res.ok !== true) {
        showToast('Fehler');
        return;
      }
      done(res.state);
    });
  }

  function clearPress() {
    if (pressTimer) {
      window.clearTimeout(pressTimer);
      pressTimer = null;
    }
    pressStart = null;
    pressTarget = null;
  }

  function bindTileLongPress(tile) {
    const photoId = tile.getAttribute('data-photo-id');
    const link = tile.querySelector('.tile-link');
    if (!photoId || !link) return;

    tile.addEventListener('pointerdown', (ev) => {
      if (ev.pointerType === 'mouse' && ev.button !== 0) return;
      longPressFired = false;
      pressStart = { x: ev.clientX, y: ev.clientY };
      pressTarget = tile;
      pressTimer = window.setTimeout(() => {
        if (pressTarget !== tile) return;
        longPressFired = true;
        ev.preventDefault();
        toggleFavById(photoId, (state) => {
          const isFav = state === 'added';
          setFavState(tile, isFav);
          showToast(isFav ? 'Gemerkt' : 'Entfernt', !isFav ? {
            actionText: 'Rückgängig',
            actionCallback: () => {
              postForm('/mobile/api_mark.php', { action: 'add', id: photoId }).then(() => {
                setFavState(tile, true);
                showToast('Gemerkt');
              });
            }
          } : {});
        });
      }, 450);
    });

    tile.addEventListener('pointermove', (ev) => {
      if (!pressStart || pressTarget !== tile) return;
      const dx = ev.clientX - pressStart.x;
      const dy = ev.clientY - pressStart.y;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist > 10) {
        clearPress();
      }
    });

    tile.addEventListener('pointerup', clearPress);
    tile.addEventListener('pointercancel', clearPress);
    tile.addEventListener('pointerleave', clearPress);

    link.addEventListener('click', (ev) => {
      if (!longPressFired) return;
      ev.preventDefault();
      ev.stopPropagation();
      longPressFired = false;
    });

    tile.addEventListener('contextmenu', (ev) => {
      if (!longPressFired) return;
      ev.preventDefault();
      longPressFired = false;
    });
  }

  function bindMenu() {
    const toggle = document.querySelector('[data-menu-toggle]');
    const overlay = document.querySelector('[data-menu-overlay]');
    if (!toggle || !overlay) return;
    const panel = overlay.querySelector('.menu-panel');

    toggle.addEventListener('click', () => {
      overlay.hidden = !overlay.hidden;
    });

    overlay.addEventListener('click', (ev) => {
      if (panel && panel.contains(ev.target)) return;
      overlay.hidden = true;
    });

    if (panel) {
      panel.querySelectorAll('a').forEach((a) => {
        a.addEventListener('click', () => {
          overlay.hidden = true;
        });
      });
    }

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        overlay.hidden = true;
      }
    });
  }

  function bindFavButtons() {
    document.querySelectorAll('[data-fav-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const photoId = btn.getAttribute('data-photo-id');
        if (!photoId) return;
        toggleFavById(photoId, (state) => {
          const isFav = state === 'added';
          btn.textContent = isFav ? 'Gemerkt' : 'Merken';
          showToast(isFav ? 'Gemerkt' : 'Entfernt');
        });
      });
    });

    document.querySelectorAll('[data-fav-remove]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const photoId = btn.getAttribute('data-photo-id');
        if (!photoId) return;
        postForm('/mobile/api_mark.php', { action: 'remove', id: photoId }).then((res) => {
          if (!res || res.ok !== true) {
            showToast('Fehler');
            return;
          }
          const tile = btn.closest('.tile');
          if (tile) tile.remove();
          showToast('Entfernt', {
            actionText: 'Rückgängig',
            actionCallback: () => {
              postForm('/mobile/api_mark.php', { action: 'add', id: photoId }).then(() => {
                window.location.reload();
              });
            }
          });
        });
      });
    });
  }

  bindMenu();
  bindFavButtons();
  document.querySelectorAll('[data-photo-tile]').forEach(bindTileLongPress);

  window.showToast = showToast;
})();
