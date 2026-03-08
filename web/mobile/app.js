(() => {
  'use strict';

  const toastRoot = document.getElementById('toast-root');
  const galleryStateKey = 'pb_gallery_state';
  let longPressFired = false;
  let pressTimer = null;
  let pressStart = null;
  let pressTarget = null;

  function parseJsonResponse(response) {
    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }

    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    if (!contentType.includes('application/json')) {
      throw new Error('INVALID_CONTENT_TYPE');
    }

    return response.json();
  }

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
    }).then(parseJsonResponse).catch(() => ({ ok: false }));
  }

  function getCurrentPath() {
    return window.location.pathname + window.location.search;
  }

  function loadGalleryState() {
    try {
      const raw = window.sessionStorage.getItem(galleryStateKey);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
      return null;
    }
  }

  function saveGalleryState(state) {
    try {
      window.sessionStorage.setItem(galleryStateKey, JSON.stringify(state));
    } catch {
      // ignore storage issues and keep navigation functional
    }
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
        showToast('Fehler aufgetreten');
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
    const toggle = document.querySelector('[data-menu-button]');
    const overlay = document.querySelector('[data-menu-overlay]');
    if (!toggle || !overlay) return;
    const panel = overlay.querySelector('.menu-panel');

    toggle.addEventListener('click', () => {
      overlay.hidden = !overlay.hidden;
      toggle.setAttribute('aria-expanded', overlay.hidden ? 'false' : 'true');
    });

    overlay.addEventListener('click', (ev) => {
      if (panel && panel.contains(ev.target)) return;
      overlay.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    });

    if (panel) {
      panel.querySelectorAll('a').forEach((a) => {
        a.addEventListener('click', () => {
          overlay.hidden = true;
          toggle.setAttribute('aria-expanded', 'false');
        });
      });
    }

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') {
        overlay.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }


  function bindPrintForms() {
    document.querySelectorAll('[data-print-form]').forEach((form) => {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const body = new URLSearchParams(new FormData(form));
        fetch('/mobile/api_print.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          },
          body,
          credentials: 'same-origin'
        }).then((res) => {
          if (!res.ok) {
            return res.json().catch(() => ({})).then((payload) => ({ ok: false, status: res.status, payload }));
          }
          return res.json().then((payload) => ({ ok: true, payload }));
        }).then((result) => {
          if (result.ok && result.payload && result.payload.ok === true) {
            showToast('In Warteschlange');
            return;
          }
          const errorCode = (result.payload && result.payload.error) || '';
          if (errorCode === 'queue_full') {
            showToast('Warteschlange voll');
            return;
          }
          showToast('Druck derzeit nicht möglich');
        }).catch(() => {
          showToast('Druck derzeit nicht möglich');
        });
      });
    });
    document.querySelectorAll('[data-print-favs-form]').forEach((form) => {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const body = new URLSearchParams(new FormData(form));
        fetch('/mobile/api_print_favs.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
          },
          body,
          credentials: 'same-origin'
        }).then((res) => {
          if (!res.ok) {
            return res.json().catch(() => ({})).then((payload) => ({ ok: false, payload }));
          }
          return res.json().then((payload) => ({ ok: true, payload }));
        }).then((result) => {
          if (result.ok && result.payload && result.payload.ok === true) {
            showToast('2 Druckjobs angelegt');
            return;
          }
          const errorCode = (result.payload && result.payload.error) || '';
          if (errorCode === 'need_two_new_favs') {
            showToast('Mindestens 2 neue gemerkte Bilder nötig');
            return;
          }
          if (errorCode === 'queue_full') {
            showToast('Warteschlange voll');
            return;
          }
          showToast('Druck derzeit nicht möglich');
        }).catch(() => {
          showToast('Druck derzeit nicht möglich');
        });
      });
    });
  }

  function bindGalleryState() {
    const gallery = document.querySelector('[data-gallery-list]');
    if (!gallery) return;

    if ('scrollRestoration' in window.history) {
      window.history.scrollRestoration = 'manual';
    }

    const state = loadGalleryState();
    if (state && state.pendingReturn === true && state.url === getCurrentPath()) {
      const scrollTarget = Number(state.scrollY) || 0;
      window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
          window.scrollTo(0, scrollTarget);
        });
      });
      state.pendingReturn = false;
      saveGalleryState(state);
    }

    document.querySelectorAll('[data-photo-link]').forEach((link) => {
      link.addEventListener('click', () => {
        saveGalleryState({
          url: getCurrentPath(),
          scrollY: window.scrollY || window.pageYOffset || 0,
          pendingReturn: false
        });
      });
    });
  }

  function bindSmartBack() {
    document.querySelectorAll('[data-smart-back]').forEach((button) => {
      button.addEventListener('click', (ev) => {
        ev.preventDefault();

        const fallbackUrl = button.getAttribute('data-fallback-url') || '/mobile/';
        const state = loadGalleryState();
        const hasSafeGalleryState = !!(state && typeof state.url === 'string' && state.url.startsWith('/mobile/'));
        if (hasSafeGalleryState) {
          state.pendingReturn = true;
          saveGalleryState(state);
        }

        let fallbackTimer = null;
        const fallback = () => {
          window.location.href = fallbackUrl;
        };

        if (hasSafeGalleryState && window.history.length > 1) {
          fallbackTimer = window.setTimeout(fallback, 500);
          window.addEventListener('pagehide', () => {
            if (fallbackTimer) {
              window.clearTimeout(fallbackTimer);
            }
          }, { once: true });
          window.history.back();
          return;
        }

        fallback();
      });
    });
  }

  function bindPhotoViewer() {
    const viewer = document.querySelector('[data-photo-viewer]');
    if (!viewer) return;

    const stage = viewer.querySelector('[data-viewer-stage]');
    const image = viewer.querySelector('[data-viewer-image]');
    if (!stage || !image) return;

    const prevUrl = (viewer.getAttribute('data-prev-url') || '').trim();
    const nextUrl = (viewer.getAttribute('data-next-url') || '').trim();
    const pointers = new Map();
    let scale = 1;
    let translateX = 0;
    let translateY = 0;
    let pinchDistance = 0;
    let pinchStartScale = 1;
    let gestureStart = null;
    let lastTapAt = 0;

    const clampScale = (value) => Math.min(4, Math.max(1, value));

    const clampPan = () => {
      const maxX = Math.max(0, ((image.clientWidth * scale) - stage.clientWidth) / 2);
      const maxY = Math.max(0, ((image.clientHeight * scale) - stage.clientHeight) / 2);
      translateX = Math.min(maxX, Math.max(-maxX, translateX));
      translateY = Math.min(maxY, Math.max(-maxY, translateY));
    };

    const applyTransform = () => {
      clampPan();
      image.style.transform = `translate3d(${translateX}px, ${translateY}px, 0) scale(${scale})`;
      image.classList.toggle('is-zoomed', scale > 1.02);
      stage.classList.toggle('is-zoomed', scale > 1.02);
    };

    const resetZoom = () => {
      scale = 1;
      translateX = 0;
      translateY = 0;
      applyTransform();
    };

    const toggleZoom = () => {
      if (scale > 1.02) {
        resetZoom();
        return;
      }
      scale = 2.2;
      translateX = 0;
      translateY = 0;
      applyTransform();
    };

    const getPointerDistance = () => {
      const values = Array.from(pointers.values());
      if (values.length < 2) return 0;
      const dx = values[0].x - values[1].x;
      const dy = values[0].y - values[1].y;
      return Math.sqrt((dx * dx) + (dy * dy));
    };

    const navigateTo = (url) => {
      if (!url) return;
      window.location.replace(url);
    };

    const onPointerEnd = (ev) => {
      const currentPoint = pointers.get(ev.pointerId) || { x: ev.clientX, y: ev.clientY };
      pointers.delete(ev.pointerId);

      if (pointers.size >= 2) {
        pinchDistance = getPointerDistance();
        pinchStartScale = scale;
        return;
      }

      if (!gestureStart) {
        return;
      }

      const dx = currentPoint.x - gestureStart.x;
      const dy = currentPoint.y - gestureStart.y;
      const movedMostlyHorizontal = Math.abs(dx) > 70 && Math.abs(dy) < 60;

      if (scale <= 1.02 && movedMostlyHorizontal) {
        if (dx < 0 && nextUrl) {
          navigateTo(nextUrl);
        } else if (dx > 0 && prevUrl) {
          navigateTo(prevUrl);
        }
      } else if (Math.abs(dx) < 10 && Math.abs(dy) < 10) {
        const now = Date.now();
        if ((now - lastTapAt) < 280) {
          toggleZoom();
          lastTapAt = 0;
        } else {
          lastTapAt = now;
        }
      }

      gestureStart = null;
    };

    image.addEventListener('load', applyTransform);

    stage.querySelectorAll('[data-viewer-prev]').forEach((button) => {
      button.addEventListener('click', () => navigateTo(prevUrl));
    });
    stage.querySelectorAll('[data-viewer-next]').forEach((button) => {
      button.addEventListener('click', () => navigateTo(nextUrl));
    });

    stage.addEventListener('dblclick', (ev) => {
      ev.preventDefault();
      toggleZoom();
    });

    stage.addEventListener('pointerdown', (ev) => {
      if (ev.pointerType === 'mouse' && ev.button !== 0) return;
      if (stage.setPointerCapture) {
        stage.setPointerCapture(ev.pointerId);
      }
      pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
      if (pointers.size === 1) {
        gestureStart = {
          x: ev.clientX,
          y: ev.clientY,
          translateX,
          translateY
        };
      } else if (pointers.size === 2) {
        pinchDistance = getPointerDistance();
        pinchStartScale = scale;
      }
    });

    stage.addEventListener('pointermove', (ev) => {
      if (!pointers.has(ev.pointerId)) return;
      pointers.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });

      if (pointers.size >= 2) {
        const currentDistance = getPointerDistance();
        if (pinchDistance > 0) {
          scale = clampScale(pinchStartScale * (currentDistance / pinchDistance));
          if (scale <= 1.02) {
            translateX = 0;
            translateY = 0;
          }
          applyTransform();
        }
        return;
      }

      if (!gestureStart) {
        return;
      }

      const dx = ev.clientX - gestureStart.x;
      const dy = ev.clientY - gestureStart.y;
      if (scale > 1.02) {
        translateX = gestureStart.translateX + dx;
        translateY = gestureStart.translateY + dy;
        applyTransform();
      }
    });

    stage.addEventListener('pointerup', onPointerEnd);
    stage.addEventListener('pointercancel', onPointerEnd);
    stage.addEventListener('pointerleave', (ev) => {
      if (ev.pointerType === 'mouse') {
        onPointerEnd(ev);
      }
    });

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowLeft' && prevUrl) {
        navigateTo(prevUrl);
      }
      if (ev.key === 'ArrowRight' && nextUrl) {
        navigateTo(nextUrl);
      }
      if (ev.key === 'Escape') {
        resetZoom();
      }
    });

    applyTransform();
  }


  function bindOrderForm() {
    const form = document.querySelector('[data-order-form]');
    if (!form) return;

    const toggle = form.querySelector('[data-shipping-toggle]');
    const fields = form.querySelector('[data-shipping-fields]');
    if (!toggle || !fields) return;

    const inputs = fields.querySelectorAll('input');
    const update = () => {
      const enabled = !!toggle.checked;
      fields.hidden = !enabled;
      inputs.forEach((input) => {
        input.required = enabled;
      });
    };

    toggle.addEventListener('change', update);
    update();
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
            showToast('Fehler aufgetreten');
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
  bindGalleryState();
  bindSmartBack();
  bindFavButtons();
  bindPrintForms();
  bindOrderForm();
  bindPhotoViewer();
  document.querySelectorAll('[data-photo-tile]').forEach(bindTileLongPress);

  window.showToast = showToast;
})();

