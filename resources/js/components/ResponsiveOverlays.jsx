import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';

const overlaySelector = '[data-responsive-overlay], [role="dialog"][aria-modal="true"], [role="alertdialog"][aria-modal="true"]';
const focusableSelector = 'button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
const isVisible = (element) => element.isConnected && !element.closest('[hidden]')
  && getComputedStyle(element).visibility !== 'hidden' && element.getClientRects().length > 0;
const focusableElements = (overlay) => [...overlay.querySelectorAll(focusableSelector)]
  .filter((element) => isVisible(element) && !element.closest('[inert]'));

/** Portals keep dialogs out of clipped/animated page containers. */
export function OverlayPortal({ children, className, ...props }) {
  if (typeof document === 'undefined') return null;
  return createPortal(className ? <div className={className} {...props}>{children}</div> : children, document.body);
}

/** One coordinator for legacy dialogs and responsive drawers; component state stays with their owners. */
export default function ResponsiveOverlays() {
  useEffect(() => {
    const records = new Map();
    const inertElements = new Map();
    let stack = [];
    let queued = false;
    let cancelled = false;
    let lastTrigger = document.activeElement;
    const triggerHistory = [];
    const rememberElement = (element) => {
      lastTrigger = element;
      if (element && element !== document.body && triggerHistory.at(-1) !== element) {
        triggerHistory.push(element);
        if (triggerHistory.length > 8) triggerHistory.shift();
      }
    };

    const restoreInert = () => {
      for (const [element, wasInert] of inertElements) {
        if (!wasInert) element.inert = false;
      }
      inertElements.clear();
    };
    const focusInside = (overlay, preferred) => {
      if (preferred && overlay.contains(preferred) && isVisible(preferred) && !preferred.closest('[inert]')) {
        preferred.focus({ preventScroll: true });
        return;
      }
      const initial = overlay.querySelector('[autofocus], [data-overlay-initial-focus]') || focusableElements(overlay)[0] || overlay;
      if (!initial.hasAttribute('tabindex') && initial === overlay) initial.setAttribute('tabindex', '-1');
      initial.focus({ preventScroll: true });
    };
    const isolate = (overlay) => {
      restoreInert();
      if (!overlay) return;
      // Never inert an ancestor of the active dialog, even when it is not portalled yet.
      for (let current = overlay; current.parentElement && current !== document.body; current = current.parentElement) {
        for (const sibling of current.parentElement.children) {
          if (sibling === current || /^(SCRIPT|STYLE|LINK)$/.test(sibling.tagName)
            || sibling.matches('[data-overlay-close], .sidebar-backdrop, .admin-mobile-sidebar-backdrop')) continue;
          inertElements.set(sibling, sibling.inert);
          sibling.inert = true;
        }
      }
    };
    const synchronize = () => {
      queued = false;
      if (cancelled) return;
      const previous = stack.at(-1);
      const previousCount = stack.length;
      const next = [...document.querySelectorAll(overlaySelector)].filter(isVisible)
        // A backdrop carrying the dialog role may contain a properly marked child; count it once.
        .filter((element, _, all) => !all.some((other) => element !== other && element.contains(other)));
      for (const element of next) {
        if (!records.has(element)) {
          const focused = document.activeElement;
          let trigger = element.contains(focused) ? lastTrigger : focused;
          if (!trigger?.isConnected || trigger === document.body || !isVisible(trigger)) {
            const previousTrigger = previous && records.get(previous)?.trigger;
            trigger = previousTrigger?.isConnected && isVisible(previousTrigger) ? previousTrigger
              : [...triggerHistory].reverse().find((candidate) => candidate.isConnected
                && !element.contains(candidate) && isVisible(candidate));
          }
          records.set(element, { trigger });
        }
      }
      // Keep an already-open underlying modal below a newly opened one, including portals.
      next.sort((a, b) => {
        const ai = stack.indexOf(a); const bi = stack.indexOf(b);
        return (ai < 0 ? Number.MAX_SAFE_INTEGER : ai) - (bi < 0 ? Number.MAX_SAFE_INTEGER : bi);
      });
      stack = next;
      const active = stack.at(-1);
      for (const element of [document.documentElement, document.body]) {
        if (element.classList.contains('responsive-overlay-active') !== Boolean(active)) {
          element.classList.toggle('responsive-overlay-active', Boolean(active));
        }
      }
      if (active !== previous || next.length !== previousCount) isolate(active);
      if (active !== previous) {
        const closing = previous && records.get(previous);
        const preferred = closing?.trigger;
        if (active) focusInside(active, active.contains(document.activeElement) ? document.activeElement : preferred);
        else if (preferred?.isConnected && isVisible(preferred) && !preferred.closest('[inert]')) preferred.focus({ preventScroll: true });
      }
      for (const element of records.keys()) if (!next.includes(element)) records.delete(element);
    };
    const schedule = () => {
      if (!queued) { queued = true; queueMicrotask(synchronize); }
    };
    const handleKey = (event) => {
      if (event.key === 'Enter' || event.key === ' ') rememberElement(document.activeElement);
      const active = stack.at(-1);
      if (!active) return;
      if (event.key === 'Escape') {
        // Let an open select dismiss its own list before dismissing the containing form.
        if (event.target.closest?.('[role="combobox"][aria-expanded="true"]')) return;
        const close = active.querySelector('[data-overlay-close], button[aria-label^="Close"], button[aria-label^="Schließen"], .admin-confirm-button.cancel, .admin-alert-dialog .admin-confirm-button, .admin-edit-actions button[type="button"], .collection-modal-close, .language-dialog-close');
        if (close && !close.disabled) {
          event.preventDefault(); event.stopImmediatePropagation(); close.click();
        }
      }
      if (event.key !== 'Tab') return;
      const elements = focusableElements(active);
      const first = elements[0]; const last = elements.at(-1);
      if (!first) { event.preventDefault(); focusInside(active); return; }
      if (event.shiftKey && (document.activeElement === first || !active.contains(document.activeElement))) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !active.contains(document.activeElement))) {
        event.preventDefault(); first.focus();
      }
    };
    const handleFocus = (event) => {
      const active = stack.at(-1);
      if (active && !active.contains(event.target)) focusInside(active);
    };
    const rememberTrigger = (event) => {
      rememberElement(event.target.closest?.(focusableSelector) || document.activeElement);
    };
    const updateViewport = () => {
      const viewport = window.visualViewport;
      document.documentElement.style.setProperty('--visible-viewport-height', `${Math.round(viewport?.height || window.innerHeight)}px`);
      document.documentElement.style.setProperty('--visible-viewport-top', `${Math.round(viewport?.offsetTop || 0)}px`);
      schedule();
    };
    const containsOverlay = (node) => node.nodeType === Node.ELEMENT_NODE
      && (node.matches(overlaySelector) || node.querySelector(overlaySelector));
    const observer = new MutationObserver((mutations) => {
      if (mutations.some((mutation) => mutation.type === 'attributes'
        ? mutation.target !== document.body && (records.has(mutation.target)
          || stack.some((overlay) => mutation.target.contains(overlay)) || containsOverlay(mutation.target))
        : [...mutation.addedNodes, ...mutation.removedNodes].some(containsOverlay))) schedule();
    });
    // Class changes matter for responsive drawers. Do not observe inert/style changes authored here.
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'hidden', 'data-responsive-overlay', 'aria-modal'] });
    document.addEventListener('keydown', handleKey, true);
    document.addEventListener('focusin', handleFocus, true);
    document.addEventListener('pointerdown', rememberTrigger, true);
    window.addEventListener('resize', updateViewport);
    window.visualViewport?.addEventListener('resize', updateViewport);
    window.visualViewport?.addEventListener('scroll', updateViewport);
    updateViewport();
    return () => {
      cancelled = true;
      observer.disconnect(); restoreInert();
      document.removeEventListener('keydown', handleKey, true);
      document.removeEventListener('focusin', handleFocus, true);
      document.removeEventListener('pointerdown', rememberTrigger, true);
      window.removeEventListener('resize', updateViewport);
      window.visualViewport?.removeEventListener('resize', updateViewport);
      window.visualViewport?.removeEventListener('scroll', updateViewport);
      document.documentElement.classList.remove('responsive-overlay-active');
      document.body.classList.remove('responsive-overlay-active');
      document.documentElement.style.removeProperty('--visible-viewport-height');
      document.documentElement.style.removeProperty('--visible-viewport-top');
    };
  }, []);
  return null;
}
