import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { createPortal } from 'react-dom';
import { getActiveOverlay, subscribeActiveOverlay } from './ResponsiveOverlays.jsx';
import { useI18n } from '../i18n.jsx';
import '../styles/toasts.css';

const ToastContext = createContext(null);
const icons = { success: 'checkCircle', error: 'errorCircle', warning: 'warningTriangle', info: 'info' };

function ToastMessage({ toast, dismiss, Icon, t }) {
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  useEffect(() => {
    if (hovered || focused || toast.duration === 0) return undefined;
    const timer = window.setTimeout(() => dismiss(toast.id), toast.duration);
    return () => window.clearTimeout(timer);
  }, [toast, dismiss, hovered, focused]);

  return <div className="app-toast" data-tone={toast.tone}
    onPointerEnter={() => setHovered(true)} onPointerLeave={() => setHovered(false)}
    onFocus={() => setFocused(true)} onBlur={(event) => {
      if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false);
    }}>
    <div className="app-toast-message" role={['error', 'warning'].includes(toast.tone) ? 'alert' : 'status'}>
      <span className="app-toast-icon" aria-hidden="true"><Icon name={icons[toast.tone]} size={20} /></span>
      <span data-i18n-skip>{t(toast.message)}</span>
    </div>
    {toast.action && <button type="button" onClick={() => { dismiss(toast.id); toast.action.onClick(); }}>
      <span data-i18n-skip>{t(toast.action.label)}</span>
    </button>}
    <button type="button" aria-label={t('Dismiss notification')} onClick={() => dismiss(toast.id)}>
      <Icon name="close" size={16} />
    </button>
  </div>;
}

export function ToastProvider({ children, Icon }) {
  const { t } = useI18n();
  const overlay = useSyncExternalStore(subscribeActiveOverlay, getActiveOverlay);
  const [items, setItems] = useState([]);
  const nextId = useRef(0);
  const dismiss = useCallback((id) => setItems((current) => current.filter((item) => item.id !== id)), []);
  const add = useCallback((tone, message, { duration = tone === 'error' ? 7000 : 4600, action = null } = {}) => {
    if (!message) return;
    const id = ++nextId.current;
    setItems((current) => [...current.filter((item) => item.message !== message || item.tone !== tone),
      { id, tone, message, duration, action }].slice(-3));
  }, []);
  const toast = useMemo(() => ({
    success: (message, options) => add('success', message, options),
    error: (message, options) => add('error', message, options),
    warning: (message, options) => add('warning', message, options),
    info: (message, options) => add('info', message, options),
  }), [add]);
  return <ToastContext.Provider value={toast}>
    {children}
    {createPortal(<div className="app-toast-stack">
      {items.map((item) => <ToastMessage key={item.id} toast={item} dismiss={dismiss} Icon={Icon} t={t} />)}
    </div>, overlay || document.body)}
  </ToastContext.Provider>;
}

export function useToast() {
  const toast = useContext(ToastContext);
  if (!toast) throw new Error('useToast must be used inside ToastProvider');
  return toast;
}
