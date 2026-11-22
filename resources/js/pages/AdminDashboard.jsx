import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { LanguageDialog, LanguageSwitcher, useI18n } from '../i18n.jsx';
import { useToast } from '../components/ToastProvider.jsx';
import AdaptiveSelect from '../components/AdaptiveSelect.jsx';
import { OverlayPortal } from '../components/ResponsiveOverlays.jsx';
import { useResponsiveLayout } from '../responsiveLayout.js';
import { ADMIN_USER_ROLE_LABELS, adminUserRoleValue, selectedAdminUserRole } from '../adminUserRoles.js';
import { fetchKlipyStickerPage } from '../klipyCatalog.js';
import AdminMediaProviders from '../components/AdminMediaProviders.jsx';

const systemSettingsBlocks = [
  { id: 'setting-general', section: 'general', label: 'General' },
  { id: 'setting-registration', section: 'registration', label: 'Registration' },
  { id: 'setting-login', section: 'login', label: 'Login' },
  { id: 'setting-captcha', section: 'captcha', label: 'Captcha' },
  { id: 'setting-social-login', section: 'social-login', label: 'Social Login' },
  { id: 'setting-live-streaming', section: 'live-streaming', label: 'Live Streaming' },
  { id: 'setting-publishing-access', section: 'publishing-access', label: 'Publishing Access' },
  { id: 'setting-streaming-engine', section: 'streaming-engine', label: 'Streaming Engine' },
  { id: 'setting-docker-configuration', section: 'docker-configuration', label: 'Docker Configuration' },
  { id: 'setting-notifications', section: 'notifications', label: 'Notifications' },
  { id: 'setting-storage', section: 'storage', label: 'Storage & CDN' },
  { id: 'setting-uploads', section: 'uploads', label: 'Uploads' },
  { id: 'setting-video-import', section: 'video-import', label: 'Video Import' },
  { id: 'setting-ads', section: 'ads', label: 'Player Ads' },
  { id: 'setting-smtp', section: 'smtp', label: 'SMTP Email' },
  { id: 'setting-sms', section: 'sms', label: 'SMS OTP' },
  { id: 'setting-moderation', section: 'moderation', label: 'Moderation Defaults' },
  { id: 'setting-integrations', section: 'integrations', label: 'Integrations' },
  { id: 'setting-maintenance', section: 'maintenance', label: 'Maintenance Mode' },
];

const captchaProviderFields = [
  ['simple', 'Simple image captcha', false, false],
  ['question', 'Question captcha', false, false],
  ['recaptcha_v2', 'Google reCAPTCHA v2', true, false],
  ['recaptcha_v3', 'Google reCAPTCHA v3', true, true],
  ['hcaptcha', 'hCaptcha', true, false],
  ['turnstile', 'Cloudflare Turnstile', true, false],
];

const captchaQuestionsText = (questions) => (Array.isArray(questions) ? questions : [])
  .map((question) => `${question.question} | ${question.answer}`)
  .join('\n');

const navigation = [
  { label: 'Dashboard', icon: 'dashboard', to: '/admin/dashboard', activePaths: ['/admin', '/admin/dashboard'] },
  { label: 'Users', icon: 'users', to: '/admin/users' },
  { label: 'Creators', icon: 'creators', to: '/admin/creators' },
  { label: 'Content', icon: 'content', to: '/admin/content', trailing: 'chevronRight' },
  { label: 'Live Streams', icon: 'live', to: '/admin/live-streams' },
  { label: 'Gifts', icon: 'gift', to: '/admin/gifts' },
  { label: 'Stickers', icon: 'sticker', to: '/admin/stickers' },
  { label: 'Ads', icon: 'ad', to: '/admin/ads' },
  { label: 'Reports', icon: 'reports', to: '/admin/reports' },
  { label: 'Monetization', icon: 'monetization', to: '/admin/monetization' },
  { label: 'Analytics', icon: 'analytics', to: '/admin/analytics' },
  { label: 'Support', icon: 'support', to: '/admin/support' },
  { label: 'Roles & Permissions', icon: 'shield', to: '/admin/roles-permissions' },
  { label: 'Website Configuration', icon: 'globe', to: '/admin/website-configuration' },
  { label: 'Pages', icon: 'content', to: '/admin/pages' },
  { label: 'SEO & Sitemaps', icon: 'search', to: '/admin/seo' },
  { label: 'Languages', icon: 'language', to: '/admin/languages' },
  { label: 'Email Templates', icon: 'mail', to: '/admin/email-templates' },
  { label: 'Plugins', icon: 'plugin', to: '/admin/plugins' },
  { label: 'System Updates', icon: 'refresh', to: '/admin/system-updates' },
  { label: 'System Settings', icon: 'settings', to: '/admin/system-settings', children: systemSettingsBlocks },
];

const pageTitles = {
  dashboard: ['Admin Dashboard', 'Monitor platform activity, moderation, revenue, and system health.'],
  notifications: ['Notifications', 'Monitor platform activity, moderation, revenue, and system health.'],
  users: ['Users', 'Manage and monitor platform users, permissions, and activity.'],
  creators: ['Creators', 'Manage creators, channel performance, verification, and creator growth.'],
  content: ['Content', 'Manage videos, shorts, posts, and content moderation across the platform.'],
  live: ['Live Streams', 'Monitor live sessions, scheduling, stream health, and live moderation.'],
  gifts: ['Gifts', 'Manage the local gift catalog, images, availability, and prices.'],
  stickers: ['Stickers', 'Configure KLIPY stickers for website and mobile comments and live streams.'],
  ads: ['Ads', 'Create, target, schedule, and measure advertising placements across the website.'],
  reports: ['Reports', 'Review flagged content, user complaints, and platform safety incidents.'],
  monetization: ['Monetization', 'Track revenue, payouts, subscriptions, ads, tips, and creator earnings.'],
  analytics: ['Analytics', 'Analyze platform growth, viewer behavior, engagement, and content performance.'],
  support: ['Support', 'Manage support tickets, help requests, escalations, and response quality.'],
  roles: ['Roles & Permissions', 'Manage admin roles, access levels, team members, and security permissions.'],
  website: ['Website Configuration', 'Manage branding, colors, typography, navigation controls, and global design tokens.'],
  sitePages: ['Pages', 'Edit localized public information, support, and legal pages and control their visibility.'],
  seo: ['SEO & Sitemaps', 'Manage search metadata, indexing rules, XML sitemaps, robots.txt, and site verification.'],
  languages: ['Languages', 'Edit installed language metadata, translations, plural forms, and patterns.'],
  emailTemplates: ['Email Templates', 'Edit localized subjects, message content, and safe dynamic placeholders for outgoing system emails.'],
  plugins: ['Plugins', 'Install and manage trusted extensions without changing PlayHub core files.'],
  updates: ['System Updates', 'Install verified PlayHub releases with automatic backups, health checks, and rollback.'],
  settings: ['System Settings', 'Configure platform preferences, integrations, security, storage, and system behavior.'],
};

const zeroTrend = Array(16).fill(0);
const adminVisualColor = (color) => {
  const normalized = String(color || '').toLowerCase();
  if (normalized === '#6b2fff') return 'var(--ad-primary)';
  if (normalized === '#8b5cf6') return 'var(--ad-primary-2)';
  return color;
};
const storageMembershipOptions = [['starter', 'Starter'], ['plus', 'Plus'], ['premium', 'Premium'], ['pro', 'Pro'], ['vip', 'VIP']];
const storageMembershipQuotaDefaults = { starter: 10, plus: 50, premium: 250, pro: 1024 };
const storageMembershipPriceDefaults = { starter: 0, plus: 9, premium: 19, pro: 49, vip: 99 };
const adminAccentPalettes = [
  ['platform', { label: 'Default', primary: '#1a73b8', primary2: '#165f99', softLight: '#e2edf7' }],
  ['violet', { primary: '#635bff', primary2: '#4f46e5', softLight: '#ebe9ff' }],
  ['indigo', { primary: '#6366f1', primary2: '#4338ca', softLight: '#e0e7ff' }],
  ['blue', { primary: '#2f80ed', primary2: '#1d4ed8', softLight: '#e7f0ff' }],
  ['sky', { primary: '#0ea5e9', primary2: '#0284c7', softLight: '#e0f2fe' }],
  ['cyan', { primary: '#06b6d4', primary2: '#0891b2', softLight: '#cffafe' }],
  ['teal', { primary: '#14b8a6', primary2: '#0f766e', softLight: '#ddfbf6' }],
  ['green', { primary: '#22c55e', primary2: '#16a34a', softLight: '#e7f8ed' }],
  ['lime', { primary: '#84cc16', primary2: '#65a30d', softLight: '#ecfccb' }],
  ['amber', { label: 'Amber', primary: '#f59e0b', primary2: '#d97706', softLight: '#fff4dc' }],
  ['obsidianGold', { label: 'Obsidian Gold', primary: '#b68a2d', primary2: '#6f5119', swatch: '#d0aa58', softLight: '#fff2cf', softDark: 'rgba(208,170,88,.22)' }],
  ['orange', { label: 'Orange', primary: '#f97316', primary2: '#ea580c', softLight: '#ffedd5' }],
  ['coral', { primary: '#ef4444', primary2: '#dc2626', softLight: '#ffe8e8' }],
  ['rose', { primary: '#f43f5e', primary2: '#e11d48', softLight: '#ffe4e6' }],
  ['pink', { primary: '#ec4899', primary2: '#db2777', softLight: '#ffe7f3' }],
  ['fuchsia', { primary: '#d946ef', primary2: '#c026d3', softLight: '#fae8ff' }],
  ['zinc', { label: 'Zinc', primary: '#71717a', primary2: '#52525b', softLight: '#e4e4e7' }],
  ['slate', { label: 'Slate', primary: '#64748b', primary2: '#475569', softLight: '#e2e8f0' }],
  ['stealthSlate', { label: 'Stealth Slate', primary: '#6f7782', primary2: '#3d434c', swatch: '#d8dde3', softLight: '#eef1f4', softDark: 'rgba(216,221,227,.18)' }],
];
const moderatorPages = new Set(['dashboard', 'notifications', 'users', 'content', 'reports']);
let adminSidebarScrollPosition = 0;
const websiteAdPlacements = [
  ['header_after', 'After website header'], ['sidebar_left', 'Left sidebar'], ['footer_before', 'Before footer'],
  ['home_feed', 'Home feed'], ['video_grid', 'Between videos'], ['comments', 'Comments'],
  ['watch_below_player', 'Below video player'], ['watch_sidebar', 'Watch sidebar'], ['watch_up_next', 'Up next sidebar videos'], ['shorts_feed', 'Shorts feed'],
  ['search_results', 'Search results'], ['channel_content', 'Channel content'], ['live_content', 'Live pages'],
  ['community_feed', 'Between community posts'], ['floating_bottom', 'Floating bottom'],
];

function adminRoleKey(user) {
  const settings = user?.settings && typeof user.settings === 'object' ? user.settings : {};
  return String(settings.role || settings.account_role || '').toLowerCase().replace(/[_-]+/g, ' ');
}

function isFullAdminUser(user) {
  const settings = user?.settings && typeof user.settings === 'object' ? user.settings : {};
  const adminSetting = settings.admin;
  const adminEnabled = typeof adminSetting === 'object' && adminSetting !== null ? Boolean(adminSetting.enabled) : Boolean(adminSetting);

  return adminEnabled || Boolean(settings.is_admin) || ['admin', 'super admin', 'owner'].includes(adminRoleKey(user));
}

function registeredPluginAdminPages() {
  try {
    const pages = window.PlayHub?.getAdminPages?.();
    return Array.isArray(pages) ? pages : [];
  } catch (_) {
    return [];
  }
}

function configuredWebsiteName() {
  return String(window.__PLAYHUB_WEBSITE__?.platformName || '').trim() || 'PlayHub';
}

function parseLiveKitLogEntries(value) {
  return String(value || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line) => {
    const [timestamp, level, component, source, message, ...detailParts] = line.split('\t');
    if (!message) return { message: line };
    const entry = { timestamp, level, component, source, message };
    const details = detailParts.join('\t').trim();
    if (details) {
      try { entry.details = JSON.parse(details); } catch { entry.details = details; }
    }
    return entry;
  });
}

function formatLiveKitLogs(logs = {}) {
  return JSON.stringify({
    livekit: parseLiveKitLogEntries(logs.livekit),
    docker: parseLiveKitLogEntries(logs.docker),
  }, null, 2);
}

function AdminDashboard({ navigate = () => {}, theme = null, user = null, path = '/admin/dashboard', search = '', api = null, website = null, adminTheme = '' }) {
  const { overlayNavigation } = useResponsiveLayout();
  const [pluginAdminPages, setPluginAdminPages] = useState(registeredPluginAdminPages);
  const canManageAdmin = isFullAdminUser(user);
  const pluginAdminPage = canManageAdmin ? pluginAdminPages.find((page) => path === page.path || path.startsWith(`${page.path}/`)) : null;
  const requestedPageKey = pluginAdminPage ? 'plugin' : getPageKey(path);
  const moderatorMode = adminRoleKey(user) === 'moderator' && !canManageAdmin;
  const pageKey = moderatorMode && !moderatorPages.has(requestedPageKey) ? 'dashboard' : requestedPageKey;
  const extensionNavigation = pluginAdminPages.map((page) => ({ id: page.plugin, label: page.label, icon: page.icon, to: page.path }));
  const fullNavigation = navigation.map((item) => item.to === '/admin/plugins' && canManageAdmin && extensionNavigation.length
    ? { ...item, children: extensionNavigation }
    : item);
  const visibleNavigation = moderatorMode ? navigation.filter((item) => moderatorPages.has(getPageKey(item.to))) : fullNavigation;
  const [title, subtitle] = pluginAdminPage ? [pluginAdminPage.title, pluginAdminPage.subtitle] : (pageTitles[pageKey] || pageTitles.dashboard);
  const [adminSearch, setAdminSearch] = useState('');
  const [adminSearchResults, setAdminSearchResults] = useState([]);
  const [adminSearchLoading, setAdminSearchLoading] = useState(false);
  const [adminSearchOpen, setAdminSearchOpen] = useState(false);
  const [adminData, setAdminData] = useState(null);
  const [adminNotifications, setAdminNotifications] = useState(null);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [notificationsLoading, setNotificationsLoading] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);
  const [languageOpen, setLanguageOpen] = useState(false);
  const [loading, setLoading] = useState(Boolean(user));
  const [error, setError] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [accountMenuOpen, setAccountMenuOpen] = useState(false);
  const [mobileNavigationOpen, setMobileNavigationOpen] = useState(false);
  const [pluginsMenuOpen, setPluginsMenuOpen] = useState(() => path === '/admin/plugins' || path.startsWith('/admin/extensions/'));
  const [systemSettingsMenuOpen, setSystemSettingsMenuOpen] = useState(() => path.startsWith('/admin/system-settings'));
  const [confirmDialog, setConfirmDialog] = useState(null);
  const [alertDialog, setAlertDialog] = useState(null);
  const [promptDialog, setPromptDialog] = useState(null);
  const [cronDialogOpen, setCronDialogOpen] = useState(false);
  const [adminProgress, setAdminProgress] = useState(null);
  const [brandTick, setBrandTick] = useState(0);
  const [editUserDialog, setEditUserDialog] = useState(null);
  const [passwordUserDialog, setPasswordUserDialog] = useState(null);
  const [roleUserDialog, setRoleUserDialog] = useState(null);
  const [emailUserDialog, setEmailUserDialog] = useState(null);
  const [banUserDialog, setBanUserDialog] = useState(null);
  const accountMenuRef = useRef(null);
  const adminHeaderRef = useRef(null);
  const adminSearchInputRef = useRef(null);
  const adminSidebarScrollRef = useRef(null);
  const userName = user?.name || 'Admin';
  const userRole = user?.settings?.role || 'Admin';
  const userHandle = user?.channel?.handle || user?.username || user?.email || userName;
  const accountAvatar = user?.channel?.avatar_url || user?.channel?.avatar_path || user?.avatar_url || user?.avatar_path;
  const accountAvatarUrl = accountAvatar ? (/^(https?:)?\/\//.test(accountAvatar) || accountAvatar.startsWith('/') ? accountAvatar : `/uploads/${accountAvatar.replace(/^public\//, '')}`) : null;
  const currentTheme = adminTheme || theme?.theme || (typeof document !== 'undefined' ? document.documentElement.dataset.theme : 'light') || 'light';
  const activeSystemSettingsBlock = systemSettingsBlockFromRoute(search, path.startsWith('/admin/system-settings') ? adminRouteTarget(search) : '');

  useEffect(() => {
    const refreshBrand = () => setBrandTick((tick) => tick + 1);
    window.addEventListener('playhub:website-config', refreshBrand);
    return () => window.removeEventListener('playhub:website-config', refreshBrand);
  }, []);

  useEffect(() => {
    const refreshPages = () => setPluginAdminPages(registeredPluginAdminPages());
    window.addEventListener('playhub:admin-pages-changed', refreshPages);
    return () => window.removeEventListener('playhub:admin-pages-changed', refreshPages);
  }, []);

  useEffect(() => {
    if (path === '/admin/plugins' || path.startsWith('/admin/extensions/')) setPluginsMenuOpen(true);
    if (path.startsWith('/admin/system-settings')) setSystemSettingsMenuOpen(true);
  }, [path]);

  useLayoutEffect(() => {
    const sidebar = adminSidebarScrollRef.current;
    if (!sidebar) return undefined;
    sidebar.scrollTop = adminSidebarScrollPosition;
    return () => { adminSidebarScrollPosition = sidebar.scrollTop; };
  }, []);

  useEffect(() => {
    setMobileNavigationOpen(false);
  }, [path, search]);

  useEffect(() => {
    if (!overlayNavigation) setMobileNavigationOpen(false);
  }, [overlayNavigation]);

  useEffect(() => {
    let cancelled = false;

    if (!user) {
      setLoading(false);
      setError('Sign in with an admin account to view this page.');
      return undefined;
    }

    if (!api?.request) {
      setLoading(false);
      setError('Admin API client is unavailable.');
      return undefined;
    }

    setLoading(true);
    setError('');
    api.request('/admin/page-data')
      .then((data) => {
        if (!cancelled) setAdminData(data || null);
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Unable to load admin data.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [api, user?.id, reloadKey]);

  useEffect(() => {
    if (adminData?.notifications) setAdminNotifications(adminData.notifications);
  }, [adminData?.notifications]);

  useEffect(() => {
    if (!accountMenuOpen && !adminSearchOpen && !notificationsOpen && !helpOpen) return undefined;
    const closeOnOutside = (event) => {
      if (!accountMenuRef.current?.contains(event.target)) setAccountMenuOpen(false);
      if (!adminHeaderRef.current?.contains(event.target)) {
        setAdminSearchOpen(false);
        setNotificationsOpen(false);
        setHelpOpen(false);
      }
    };
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') {
        setAccountMenuOpen(false);
        setAdminSearchOpen(false);
        setNotificationsOpen(false);
        setHelpOpen(false);
      }
    };
    document.addEventListener('mousedown', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('mousedown', closeOnOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [accountMenuOpen, adminSearchOpen, notificationsOpen, helpOpen]);

  useEffect(() => {
    const query = adminSearch.trim();
    if (!query || !api?.request) {
      setAdminSearchResults([]);
      setAdminSearchLoading(false);
      return undefined;
    }

    let cancelled = false;
    setAdminSearchLoading(true);
    const timer = window.setTimeout(() => {
      api.request(`/admin/search?q=${encodeURIComponent(query)}`)
        .then((data) => {
          if (!cancelled) setAdminSearchResults(Array.isArray(data?.results) ? data.results : []);
        })
        .catch(() => {
          if (!cancelled) setAdminSearchResults([]);
        })
        .finally(() => {
          if (!cancelled) setAdminSearchLoading(false);
        });
    }, 250);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [api, adminSearch]);

  useEffect(() => {
    const focusSearch = (event) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        adminSearchInputRef.current?.focus();
        setAdminSearchOpen(true);
      }
    };
    document.addEventListener('keydown', focusSearch);
    return () => document.removeEventListener('keydown', focusSearch);
  }, []);

  const refreshAdminData = () => setReloadKey((key) => key + 1);
  const refreshAdminNotifications = async () => {
    if (!api?.request) return;
    setNotificationsLoading(true);
    try {
      const data = await api.request('/admin/notifications');
      setAdminNotifications(data || null);
    } catch (_) {
      setAdminNotifications((current) => current || { count: 0, critical_count: 0, items: [] });
    } finally {
      setNotificationsLoading(false);
    }
  };
  const markAdminNotificationReadLocal = useCallback((id = null, all = false) => {
    setAdminNotifications((current) => {
      if (!current) return current;
      const targetId = id === null ? null : String(id);
      let changed = 0;
      const items = (current.items || []).map((item) => {
        const shouldMark = all || String(item.id) === targetId;
        const wasUnread = item.displayUnread ?? item.unread;
        if (!shouldMark || !wasUnread) return item;
        changed += 1;
        return { ...item, unread: false, displayUnread: false };
      });

      return {
        ...current,
        unread_count: all ? 0 : Math.max(0, Number(current.unread_count || 0) - changed),
        items,
      };
    });
  }, []);
  const markAdminNotificationRead = useCallback(async (item) => {
    const isUnread = item?.displayUnread ?? item?.unread;
    if (!api?.request || !item?.id || !isUnread) return;
    markAdminNotificationReadLocal(item.id);
    try {
      await api.request(`/admin/notifications/${encodeURIComponent(item.id)}/read`, { method: 'POST' });
    } catch (_) {}
  }, [api, markAdminNotificationReadLocal]);
  const markAllAdminNotificationsRead = useCallback(async () => {
    if (!api?.request) return;
    markAdminNotificationReadLocal(null, true);
    try {
      const response = await api.request('/admin/notifications/read', { method: 'POST' });
      if (response?.notifications) setAdminNotifications(response.notifications);
    } catch (_) {}
  }, [api, markAdminNotificationReadLocal]);
  useEffect(() => {
    if (adminRouteParam(search, 'open') !== 'notifications') return;
    setHelpOpen(false);
    setAdminSearchOpen(false);
    setNotificationsOpen(true);
    refreshAdminNotifications();
    window.setTimeout(() => document.getElementById('admin-notifications-button')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
  }, [search]);
  const confirmAdminAction = (dialog) => new Promise((resolve) => setConfirmDialog({ ...dialog, resolve }));
  const closeConfirmDialog = (confirmed) => {
    setConfirmDialog((dialog) => {
      dialog?.resolve?.(confirmed);
      return null;
    });
  };
  const alertAdminAction = (dialog) => new Promise((resolve) => setAlertDialog({ ...dialog, resolve }));
  const closeAlertDialog = () => {
    setAlertDialog((dialog) => {
      dialog?.resolve?.();
      return null;
    });
  };
  const promptAdminAction = (dialog) => new Promise((resolve) => setPromptDialog({ ...dialog, resolve }));
  const closePromptDialog = (payload = null) => {
    setPromptDialog((dialog) => {
      dialog?.resolve?.(payload);
      return null;
    });
  };
  const openCronJobsAction = useCallback(() => setCronDialogOpen(true), []);
  const editUserAction = (row) => new Promise((resolve) => setEditUserDialog({ row, resolve, canManageAdmin }));
  const closeEditUserDialog = (payload = null) => {
    setEditUserDialog((dialog) => {
      dialog?.resolve?.(payload);
      return null;
    });
  };
  const passwordUserAction = (row) => new Promise((resolve) => setPasswordUserDialog({ row, resolve }));
  const closePasswordUserDialog = (payload = null) => {
    setPasswordUserDialog((dialog) => {
      dialog?.resolve?.(payload);
      return null;
    });
  };
  const roleUserAction = (row) => new Promise((resolve) => setRoleUserDialog({ row, resolve }));
  const closeRoleUserDialog = (payload = null) => {
    setRoleUserDialog((dialog) => {
      dialog?.resolve?.(payload);
      return null;
    });
  };
  const emailUserAction = (row) => new Promise((resolve) => setEmailUserDialog({ row, resolve }));
  const closeEmailUserDialog = (payload = null) => {
    setEmailUserDialog((dialog) => {
      dialog?.resolve?.(payload);
      return null;
    });
  };
  const banUserAction = (row) => new Promise((resolve) => setBanUserDialog({ row, resolve }));
  const closeBanUserDialog = (payload = null) => {
    setBanUserDialog((dialog) => {
      dialog?.resolve?.(payload);
      return null;
    });
  };
  const page = useMemo(() => pluginAdminPage
    ? <PluginAdminPage page={pluginAdminPage} api={api} navigate={navigate} user={user} confirmAction={confirmAdminAction} alertAction={alertAdminAction} />
    : renderAdminPage(pageKey, navigate, adminData, api, refreshAdminData, confirmAdminAction, editUserAction, passwordUserAction, roleUserAction, emailUserAction, banUserAction, alertAdminAction, promptAdminAction, search, canManageAdmin, setAdminNotifications, openCronJobsAction), [pageKey, pluginAdminPage, navigate, adminData, api, search, canManageAdmin, openCronJobsAction, user]);
  const isActive = (item) => (item.activePaths || [item.to]).some((activePath) => path === activePath || (activePath !== '/admin' && path.startsWith(`${activePath}/`)))
    || item.children?.some((child) => child.to && (path === child.to || path.startsWith(`${child.to}/`)));
  const handlePageAction = (action) => runAdminPageAction(pageKey, action.label, { api, navigate, adminData, onRefresh: refreshAdminData, alertAction: alertAdminAction, promptAction: promptAdminAction, setAdminNotifications, setAdminProgress });
  const pageActions = canManageAdmin ? getPageActions(pageKey) : [];
  const goAccount = (to) => {
    setAccountMenuOpen(false);
    navigate(to);
  };
  const openLanguageDialog = () => {
    setAccountMenuOpen(false);
    setLanguageOpen(true);
  };
  const navigateFromMobileMenu = (to) => {
    if (adminSidebarScrollRef.current) adminSidebarScrollPosition = adminSidebarScrollRef.current.scrollTop;
    setMobileNavigationOpen(false);
    navigate(to);
  };
  const navigationSectionOpen = (item) => item.to === '/admin/plugins' ? pluginsMenuOpen : systemSettingsMenuOpen;
  const openNavigationSection = (item) => {
    if (item.to === '/admin/plugins') {
      setPluginsMenuOpen((open) => path === item.to ? !open : true);
      if (path !== item.to) navigateFromMobileMenu(item.to);
      return;
    }
    setSystemSettingsMenuOpen((open) => !open);
  };
  const signOut = async () => {
    setAccountMenuOpen(false);
    try { await api?.request?.('/auth/logout', { method: 'POST' }); } catch (_) {}
    try { localStorage.removeItem('playhub_token'); } catch (_) {}
    try { sessionStorage.removeItem('playhub_token'); } catch (_) {}
    if (typeof window !== 'undefined') window.location.href = '/login';
  };
  const goAdminSearchResult = (result) => {
    if (!result?.to) return;
    setAdminSearch('');
    setAdminSearchOpen(false);
    navigate(result.to);
  };
  const submitAdminSearch = (event) => {
    event.preventDefault();
    if (adminSearchResults[0]) goAdminSearchResult(adminSearchResults[0]);
  };
  const toggleAdminNotifications = () => {
    setHelpOpen(false);
    setAdminSearchOpen(false);
    setNotificationsOpen((open) => {
      const next = !open;
      if (next) refreshAdminNotifications();
      return next;
    });
  };
  const toggleAdminTheme = () => {
    theme?.toggleTheme?.();
  };

  return (
    <div className={`admin-dashboard-shell admin-page-${pageKey}`} data-admin-theme={currentTheme} data-navigation-mode={overlayNavigation ? 'overlay' : 'docked'}>
      {overlayNavigation && mobileNavigationOpen && <button type="button" className="admin-mobile-sidebar-backdrop" data-overlay-close onClick={() => setMobileNavigationOpen(false)} aria-label="Close admin navigation" />}
      <aside id="admin-mobile-navigation" className={`admin-sidebar ${mobileNavigationOpen ? 'is-mobile-open' : ''}`} aria-label="Admin navigation" data-responsive-overlay={overlayNavigation && mobileNavigationOpen ? 'navigation' : undefined} role={overlayNavigation && mobileNavigationOpen ? 'dialog' : undefined} aria-modal={overlayNavigation && mobileNavigationOpen ? true : undefined} inert={overlayNavigation && !mobileNavigationOpen ? true : undefined}>
        <div className="admin-sidebar-brand">
          <button type="button" className="admin-brand-button" onClick={() => navigateFromMobileMenu('/admin/dashboard')} aria-label="Go to admin dashboard">
            <AdminLogo tick={brandTick} />
          </button>
          <button type="button" className="admin-mobile-sidebar-close" data-overlay-close onClick={() => setMobileNavigationOpen(false)} aria-label="Close admin navigation"><AdminIcon name="close" size={21} /></button>
        </div>

        <div className="admin-sidebar-scroll" ref={adminSidebarScrollRef} onScroll={(event) => { adminSidebarScrollPosition = event.currentTarget.scrollTop; }}>
          <div className="admin-nav-group">
            <p>Admin</p>
            {visibleNavigation.map((item) => item.children?.length ? (
              <div className="admin-nav-section" key={item.label}>
                <button
                  type="button"
                  className={`admin-nav-item ${isActive(item) ? 'active' : ''}`}
                  aria-current={isActive(item) ? 'page' : undefined}
                  aria-expanded={navigationSectionOpen(item)}
                  aria-controls={`admin-${item.to === '/admin/plugins' ? 'plugins' : 'system-settings'}-submenu`}
                  onClick={() => openNavigationSection(item)}
                >
                  <AdminIcon name={item.icon} size={19} />
                  <span>{item.label}</span>
                  <i className={`admin-nav-caret ${navigationSectionOpen(item) ? 'open' : ''}`}><AdminIcon name="chevronDown" size={15} /></i>
                </button>
                {navigationSectionOpen(item) && <div className="admin-nav-submenu" id={`admin-${item.to === '/admin/plugins' ? 'plugins' : 'system-settings'}-submenu`} aria-label={item.label}>
                  {item.children.map((child) => { const childActive = child.to ? path === child.to || path.startsWith(`${child.to}/`) : path.startsWith('/admin/system-settings') && activeSystemSettingsBlock.id === child.id; return <button
                    type="button"
                    key={child.id}
                    className={`admin-nav-subitem ${child.to ? 'plugin' : ''} ${childActive ? 'active' : ''}`.trim()}
                    aria-current={childActive ? 'page' : undefined}
                    onClick={() => navigateFromMobileMenu(child.to || `${item.to}?section=${encodeURIComponent(child.section)}`)}
                  >{child.icon ? <AdminIcon name={child.icon} size={15} /> : <i aria-hidden="true" />}<span>{child.label}</span></button>; })}
                </div>}
              </div>
            ) : (
              <button
                type="button"
                key={item.label}
                className={`admin-nav-item ${isActive(item) ? 'active' : ''}`}
                aria-current={isActive(item) ? 'page' : undefined}
                onClick={() => { setPluginsMenuOpen(false); setSystemSettingsMenuOpen(false); navigateFromMobileMenu(item.to); }}
              >
                <AdminIcon name={item.icon} size={19} />
                <span>{item.label}</span>
                {item.trailing && <AdminIcon name={item.trailing} size={15} />}
              </button>
            ))}
          </div>
        </div>

        <button type="button" className="admin-account-card" onClick={() => navigateFromMobileMenu('/profile')}>
          <AdminAvatar name={userName} image={accountAvatarUrl} tone="orange" />
          <span>
            <strong>{userName}</strong>
            <small>{userRole}</small>
          </span>
          <AdminIcon name="chevronDown" size={16} />
        </button>
      </aside>

        <section className="admin-workspace">
          <header className="admin-topbar" ref={adminHeaderRef}>
          <button type="button" className="admin-mobile-menu-button" onClick={() => setMobileNavigationOpen(true)} aria-label="Open admin navigation" aria-controls="admin-mobile-navigation" aria-expanded={mobileNavigationOpen}><AdminIcon name="menu" size={21} /></button>
          <form className="admin-search" onSubmit={submitAdminSearch}>
            <AdminIcon name="search" size={18} />
            <input ref={adminSearchInputRef} aria-label="Search admin dashboard" value={adminSearch} onFocus={() => setAdminSearchOpen(true)} onChange={(event) => { setAdminSearch(event.target.value); setAdminSearchOpen(true); }} placeholder="Search users, videos, creators, reports..." />
            {adminSearch && <button type="button" className="search-clear-button admin-search-clear" onClick={() => { setAdminSearch(''); setAdminSearchResults([]); }} aria-label="Clear search"><AdminIcon name="close" size={14} /></button>}
            <button type="submit" className="admin-search-submit" aria-label="Submit admin search"><AdminIcon name="enter" size={14} /></button>
            {adminSearchOpen && adminSearch.trim() && <AdminSearchDropdown query={adminSearch} results={adminSearchResults} loading={adminSearchLoading} onGo={goAdminSearchResult} />}
          </form>
            <nav className="admin-top-actions" aria-label="Admin quick controls">
            <LanguageSwitcher compact />
            <button type="button" className="admin-icon-button" aria-label="Appearance" onClick={toggleAdminTheme}><AdminIcon name={currentTheme === 'dark' ? 'sun' : 'moon'} size={21} /></button>
            <div className="admin-header-popover-wrap">
              <button type="button" className={`admin-icon-button ${helpOpen ? 'active' : ''}`} aria-label="Help center" onClick={() => { setHelpOpen((open) => !open); setNotificationsOpen(false); setAdminSearchOpen(false); }}><AdminIcon name="support" size={21} /></button>
              {helpOpen && <AdminHelpDropdown pageKey={pageKey} title={title} subtitle={subtitle} onGo={(to) => { setHelpOpen(false); navigate(to); }} onRefresh={() => { setHelpOpen(false); refreshAdminData(); }} />}
            </div>
            <div className="admin-header-popover-wrap">
              <button type="button" id="admin-notifications-button" className={`admin-icon-button admin-bell-button ${notificationsOpen ? 'active' : ''}`} aria-label="Notifications" onClick={toggleAdminNotifications}><AdminIcon name="bell" size={21} />{(adminNotifications?.unread_count || 0) > 0 && <span aria-hidden="true" />}</button>
              {notificationsOpen && <AdminNotificationsDropdown payload={adminNotifications} loading={notificationsLoading} onRefresh={refreshAdminNotifications} onRead={markAdminNotificationRead} onReadAll={markAllAdminNotificationsRead} onGo={(to) => { setNotificationsOpen(false); navigate(to); }} />}
            </div>
            <div className="admin-account-menu-wrap" ref={accountMenuRef}>
              <button type="button" className={`admin-avatar-button ${accountMenuOpen ? 'active' : ''}`} onClick={() => setAccountMenuOpen((open) => !open)} aria-label="Open account menu" aria-haspopup="menu" aria-expanded={accountMenuOpen}>
                <AdminAvatar name={userName} image={accountAvatarUrl} tone="orange" />
              </button>
              <button type="button" className={`admin-mini-chevron ${accountMenuOpen ? 'active' : ''}`} onClick={() => setAccountMenuOpen((open) => !open)} aria-label="Open profile menu" aria-haspopup="menu" aria-expanded={accountMenuOpen}><AdminIcon name="chevronDown" size={18} /></button>
              {accountMenuOpen && <AdminAccountDropdown
                avatarUrl={accountAvatarUrl}
                onGo={goAccount}
                onLanguage={openLanguageDialog}
                onSignOut={signOut}
                userHandle={userHandle}
                userName={userName}
              />}
            </div>
          </nav>
        </header>
        {adminProgress && <div className="admin-top-progress" role="status" aria-live="polite">
          <div><span>{adminProgress.label || 'Processing'}</span><strong>{Math.round(adminProgress.percent || 0)}%</strong></div>
          <p>{adminProgress.text || 'Working...'}</p>
          <i><b style={{ width: `${Math.max(0, Math.min(100, adminProgress.percent || 0))}%` }} /></i>
        </div>}

        <main className="admin-main">
          <PageHeader title={title} subtitle={subtitle} actions={pageActions} onAction={handlePageAction} />
          {(loading || error) && <div className="state-card">{loading ? 'Loading admin data...' : error}</div>}
          {page}
        </main>
      </section>
      <OverlayPortal className="admin-dashboard-shell admin-overlay-root" data-admin-theme={currentTheme}>
      {confirmDialog && <AdminConfirmDialog dialog={confirmDialog} onClose={closeConfirmDialog} />}
      {promptDialog && <AdminPromptDialog dialog={promptDialog} onClose={closePromptDialog} />}
      {cronDialogOpen && <AdminCronJobsDialog api={api} alertAction={alertAdminAction} onClose={() => setCronDialogOpen(false)} />}
      {editUserDialog && <AdminEditUserDialog dialog={editUserDialog} onClose={closeEditUserDialog} />}
      {passwordUserDialog && <AdminPasswordUserDialog dialog={passwordUserDialog} onClose={closePasswordUserDialog} />}
      {roleUserDialog && <AdminRoleUserDialog dialog={roleUserDialog} onClose={closeRoleUserDialog} />}
      {emailUserDialog && <AdminEmailUserDialog dialog={emailUserDialog} onClose={closeEmailUserDialog} />}
      {banUserDialog && <AdminBanUserDialog dialog={banUserDialog} onClose={closeBanUserDialog} />}
      {alertDialog && <AdminAlertDialog dialog={alertDialog} onClose={closeAlertDialog} />}
      <LanguageDialog open={languageOpen} onClose={() => setLanguageOpen(false)} />
      </OverlayPortal>
    </div>
  );
}

function PluginAdminPage({ page, api, navigate, user, confirmAction, alertAction }) {
  const hostRef = useRef(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!hostRef.current) return undefined;
    setError('');
    hostRef.current.replaceChildren();
    try {
      const cleanup = page.mount(hostRef.current, {
        request: (requestPath, options = {}) => api?.request?.(requestPath, options),
        navigate,
        user,
        confirm: confirmAction,
        alert: alertAction,
      });
      return typeof cleanup === 'function' ? cleanup : undefined;
    } catch (mountError) {
      setError(mountError?.message || 'The plugin workspace could not be opened.');
      return undefined;
    }
  }, [page, api, navigate, user?.id]);

  return <section className="admin-plugin-page-host" data-plugin={page.plugin}>
    {error && <div className="state-card">{error}</div>}
    <div ref={hostRef} />
  </section>;
}

function AdminConfirmDialog({ dialog, onClose }) {
  const tone = dialog.tone === 'danger' ? 'danger' : 'primary';
  const [checked, setChecked] = useState(false);

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose(false);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose(false)}>
      <section className={`admin-confirm-dialog tone-${tone}`} role="dialog" aria-modal="true" aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-message">
        <span className="admin-confirm-icon"><AdminIcon name={tone === 'danger' ? 'warning' : 'shield'} size={22} /></span>
        <div className="admin-confirm-copy">
          <h2 id="admin-confirm-title">{dialog.title || 'Confirm action'}</h2>
          <p id="admin-confirm-message">{dialog.message || 'Are you sure you want to continue?'}</p>
        </div>
        {dialog.checkbox && <label className="admin-checkbox-field admin-confirm-checkbox"><input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} /><span>{dialog.checkbox.label}</span></label>}
        <div className="admin-confirm-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={() => onClose(false)}>Cancel</button>
          <button type="button" className={`admin-confirm-button ${tone}`} disabled={dialog.checkbox?.required && !checked} onClick={() => onClose(dialog.checkbox ? { confirmed: true, [dialog.checkbox.name || 'checked']: checked } : true)}>{dialog.confirmLabel || 'Confirm'}</button>
        </div>
      </section>
    </div>
  );
}

function AdminAlertDialog({ dialog, onClose }) {
  const tone = dialog.tone === 'danger' ? 'danger' : 'primary';

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <section className={`admin-confirm-dialog admin-alert-dialog tone-${tone}`} role="alertdialog" aria-modal="true" aria-labelledby="admin-alert-title" aria-describedby="admin-alert-message">
        <span className="admin-confirm-icon"><AdminIcon name={tone === 'danger' ? 'warning' : 'support'} size={22} /></span>
        <div className="admin-confirm-copy">
          <h2 id="admin-alert-title">{dialog.title || 'Notice'}</h2>
          <p id="admin-alert-message">{dialog.message || 'Action completed.'}</p>
        </div>
        <div className="admin-confirm-actions">
          <button type="button" className="admin-confirm-button primary" onClick={onClose}>OK</button>
        </div>
      </section>
    </div>
  );
}

function AdminBackupDialog({ api, alertAction = null, onClose, onRefresh = null }) {
  const [payload, setPayload] = useState(null);
  const [selected, setSelected] = useState([]);
  const [form, setForm] = useState({ enabled: false, frequency: 'daily', time: '02:00', day_of_week: 0, day_of_month: 1, include_database: true, large_file_action: 'store', large_file_threshold_mb: 512, compression_level: 3, retention_count: 5 });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [scheduleSaving, setScheduleSaving] = useState(false);
  const [error, setError] = useState('');
  const activeBackupRef = useRef(null);
  const items = Array.isArray(payload?.items) ? payload.items : [];
  const backups = Array.isArray(payload?.backups) ? payload.backups : [];
  const activeBackup = backups.find((backup) => ['queued', 'processing'].includes(backup.status));
  const selectedBytes = items.filter((item) => selected.includes(item.name)).reduce((total, item) => total + Number(item.size_bytes || 0), 0);

  const loadBackups = useCallback(async (inventory = true) => {
    if (!api?.request) {
      setError('Admin API client is unavailable.');
      setLoading(false);
      return;
    }
    if (inventory) setLoading(true);
    setError('');
    try {
      const data = await api.request(`/admin/backups${inventory ? '' : '?inventory=0'}`);
      setPayload((current) => inventory ? data : { ...(current || {}), ...data, items: current?.items || [] });
      if (inventory) {
        setForm((current) => ({ ...current, ...(data?.defaults || {}), ...(data?.settings || {}) }));
        const available = new Set((data?.items || []).filter((item) => item.selectable).map((item) => item.name));
        const scheduled = (data?.settings?.selections || []).filter((name) => available.has(name));
        setSelected(scheduled.length ? scheduled : (data?.items || []).filter((item) => item.selectable && !['node_modules', '.git'].includes(item.name) && !/\.(?:zip|tar|gz)$/i.test(item.name)).map((item) => item.name));
      }
    } catch (requestError) {
      setError(requestError.message || 'Backup information could not be loaded.');
    } finally {
      if (inventory) setLoading(false);
    }
  }, [api]);

  useEffect(() => { loadBackups(true); }, [loadBackups]);
  useEffect(() => {
    if (activeBackup?.id) {
      activeBackupRef.current = activeBackup.id;
    } else if (activeBackupRef.current) {
      activeBackupRef.current = null;
      onRefresh?.();
    }
  }, [activeBackup?.id, onRefresh]);
  useEffect(() => {
    if (!activeBackup) return undefined;
    const timer = window.setInterval(() => loadBackups(false), 3000);
    return () => window.clearInterval(timer);
  }, [activeBackup?.id, loadBackups]);
  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape' && !saving && !scheduleSaving) onClose(); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose, saving, scheduleSaving]);

  const toggleItem = (name) => setSelected((current) => current.includes(name) ? current.filter((item) => item !== name) : [...current, name]);
  const setAll = (checked) => setSelected(checked ? items.filter((item) => item.selectable).map((item) => item.name) : []);
  const startBackup = async (event) => {
    event.preventDefault();
    if (!api?.request || saving || !selected.length || activeBackup) return;
    setSaving(true);
    setError('');
    try {
      const data = await api.request('/admin/backups', { method: 'POST', body: JSON.stringify({ ...form, selections: selected }) });
      setPayload((current) => ({ ...(current || {}), backups: [data.backup, ...(current?.backups || [])] }));
      onRefresh?.();
    } catch (requestError) {
      setError(requestError.message || 'The backup could not be started.');
    } finally {
      setSaving(false);
    }
  };
  const saveSchedule = async () => {
    if (!api?.request || scheduleSaving || !selected.length) return;
    setScheduleSaving(true);
    setError('');
    try {
      const data = await api.request('/admin/backups/settings', { method: 'PUT', body: JSON.stringify({ ...form, selections: selected }) });
      setPayload((current) => ({ ...(current || {}), settings: data.settings, summary: data.summary }));
      onRefresh?.();
      void alertAction?.({ title: 'Backup schedule saved', message: data.message || 'Automatic backup schedule saved.' });
    } catch (requestError) {
      const message = requestError.message || 'The automatic backup schedule could not be saved.';
      setError(message);
      void alertAction?.({ title: 'Backup schedule failed', message, tone: 'danger' });
    } finally {
      setScheduleSaving(false);
    }
  };
  const deleteBackup = async (backup) => {
    if (!api?.request || ['queued', 'processing'].includes(backup.status)) return;
    try {
      await api.request(`/admin/backups/${encodeURIComponent(backup.id)}`, { method: 'DELETE' });
      await loadBackups(false);
      onRefresh?.();
    } catch (requestError) {
      void alertAction?.({ title: 'Backup delete failed', message: requestError.message || 'The backup could not be deleted.', tone: 'danger' });
    }
  };

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !saving && !scheduleSaving && onClose()}>
      <form className="admin-edit-user-dialog admin-backup-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-backup-title" onSubmit={startBackup}>
        <div className="admin-edit-user-head">
          <span className="admin-confirm-icon"><AdminIcon name="cloud" size={22} /></span>
          <span><h2 id="admin-backup-title">System Backups</h2><p>Run a backup now or configure an automatic schedule.</p></span>
          <button type="button" className="admin-edit-dialog-close" onClick={onClose} disabled={saving || scheduleSaving} aria-label="Close backup dialog"><AdminIcon name="close" size={17} /></button>
        </div>
        {error && <p className="admin-edit-error">{error}</p>}
        {loading ? <div className="state-card">Calculating folder and file sizes...</div> : <>
          <div className="admin-backup-capacity">
            <span><small>Selected</small><strong>{formatBackupBytes(selectedBytes)}</strong></span>
            <span><small>Disk free</small><strong>{payload?.storage?.free || 'Unavailable'}</strong></span>
            <span><small>Server disk</small><strong>{payload?.storage?.total || 'Unavailable'}</strong></span>
          </div>
          <section className="admin-backup-schedule">
            <label className="admin-checkbox-field"><input type="checkbox" checked={form.enabled} onChange={(event) => setForm((current) => ({ ...current, enabled: event.target.checked }))} disabled={scheduleSaving} /><span><strong>Automatic backups</strong><small>Runs through the active Laravel scheduler cron job.</small></span></label>
            <div>
              <label className="admin-edit-field"><span>Frequency</span><select value={form.frequency} onChange={(event) => setForm((current) => ({ ...current, frequency: event.target.value }))} disabled={!form.enabled || scheduleSaving}><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>
              {form.frequency === 'weekly' && <label className="admin-edit-field"><span>Day</span><select value={form.day_of_week} onChange={(event) => setForm((current) => ({ ...current, day_of_week: Number(event.target.value) }))} disabled={!form.enabled || scheduleSaving}><option value="0">Sunday</option><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option></select></label>}
              {form.frequency === 'monthly' && <label className="admin-edit-field"><span>Day of month</span><input type="number" min="1" max="28" value={form.day_of_month} onChange={(event) => setForm((current) => ({ ...current, day_of_month: Number(event.target.value) }))} disabled={!form.enabled || scheduleSaving} /></label>}
              <label className="admin-edit-field"><span>Time</span><input type="time" value={form.time} onChange={(event) => setForm((current) => ({ ...current, time: event.target.value }))} disabled={!form.enabled || scheduleSaving} /></label>
              <button type="button" className="admin-outline-action primary-text" onClick={saveSchedule} disabled={scheduleSaving || !selected.length}>{scheduleSaving ? 'Saving...' : 'Save schedule'}</button>
            </div>
          </section>
          <div className="admin-backup-section-head">
            <span><strong>Website files and folders</strong><small>{selected.length} of {items.filter((item) => item.selectable).length} selected</small></span>
            <label><input type="checkbox" checked={items.some((item) => item.selectable) && selected.length === items.filter((item) => item.selectable).length} onChange={(event) => setAll(event.target.checked)} />Select all</label>
          </div>
          <div className="admin-backup-file-list">
            {items.map((item) => <label key={item.name} className={!item.selectable ? 'disabled' : ''}>
              <input type="checkbox" checked={selected.includes(item.name)} onChange={() => toggleItem(item.name)} disabled={!item.selectable || saving || Boolean(activeBackup)} />
              <span className="admin-backup-file-icon"><AdminIcon name={item.type === 'folder' ? 'content' : item.type === 'link' ? 'link' : 'reports'} size={17} /></span>
              <span><strong>{item.name}</strong><small>{item.type === 'folder' ? `${Number(item.files || 0).toLocaleString()} files` : item.type}</small></span>
              <b>{item.size}</b>
            </label>)}
          </div>
          <div className="admin-backup-options">
            <label className="admin-checkbox-field"><input type="checkbox" checked={form.include_database} onChange={(event) => setForm((current) => ({ ...current, include_database: event.target.checked }))} disabled={saving || Boolean(activeBackup)} /><span>Include a consistent database dump</span></label>
            <label className="admin-edit-field"><span>Large files</span><select value={form.large_file_action} onChange={(event) => setForm((current) => ({ ...current, large_file_action: event.target.value }))} disabled={saving || Boolean(activeBackup)}><option value="store">Store without compression</option><option value="compress">Compress normally</option><option value="skip">Skip large files</option></select><small>Files above the threshold can be stored faster, compressed, or omitted.</small></label>
            <label className="admin-edit-field"><span>Large-file threshold (MB)</span><input type="number" min="1" max="1048576" value={form.large_file_threshold_mb} onChange={(event) => setForm((current) => ({ ...current, large_file_threshold_mb: Number(event.target.value) }))} disabled={saving || Boolean(activeBackup)} /></label>
            <label className="admin-edit-field"><span>Compression</span><select value={form.compression_level} onChange={(event) => setForm((current) => ({ ...current, compression_level: Number(event.target.value) }))} disabled={saving || Boolean(activeBackup)}><option value="0">None</option><option value="3">Fast</option><option value="6">Balanced</option><option value="9">Maximum</option></select></label>
            <label className="admin-edit-field"><span>Keep latest backups</span><input type="number" min="1" max="50" value={form.retention_count} onChange={(event) => setForm((current) => ({ ...current, retention_count: Number(event.target.value) }))} disabled={saving || Boolean(activeBackup)} /></label>
          </div>
          <div className="admin-backup-history-head"><strong>Backup history</strong><button type="button" onClick={() => loadBackups(false)} disabled={saving}>Refresh</button></div>
          <div className="admin-backup-history">
            {backups.length ? backups.map((backup) => <div key={backup.id}>
              <span className={`admin-backup-status status-${backup.status}`}><i /><b>{backup.status}</b></span>
              <span><strong>{backup.archive || `Backup ${backup.id.slice(0, 8)}`}</strong><small>{backup.source === 'automatic' ? 'Automatic · ' : ''}{backup.created_at ? new Date(backup.created_at).toLocaleString() : ''}{backup.status === 'failed' && backup.error ? ` · ${backup.error}` : ''}</small></span>
              <span className="admin-backup-history-size"><strong>{backup.status === 'completed' ? backup.size : `${backup.progress || 0}%`}</strong><small>{backup.included_files ? `${backup.included_files.toLocaleString()} files` : backup.selected_size}</small></span>
              <span className="admin-backup-history-actions">
                {backup.download_url && <button type="button" onClick={() => downloadBackupArchive(api, backup, alertAction)} aria-label="Download backup"><AdminIcon name="download" size={15} /></button>}
                {!['queued', 'processing'].includes(backup.status) && <button type="button" className="danger" onClick={() => deleteBackup(backup)} aria-label="Delete backup"><AdminIcon name="trash" size={15} /></button>}
              </span>
              {['queued', 'processing'].includes(backup.status) && <i className="admin-backup-progress"><b style={{ width: `${Math.max(1, backup.progress || 0)}%` }} /></i>}
            </div>) : <p className="admin-cron-empty">No backups have been created yet.</p>}
          </div>
        </>}
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={onClose} disabled={saving || scheduleSaving}>Close</button>
          <button type="submit" className="admin-confirm-button primary" disabled={loading || saving || !selected.length || Boolean(activeBackup)}>{activeBackup ? `Backup ${activeBackup.progress || 0}%` : saving ? 'Queuing...' : 'Start Backup'}</button>
        </div>
      </form>
    </div>
  );
}

function formatBackupBytes(bytes = 0) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = Math.max(0, Number(bytes) || 0);
  let index = 0;
  while (value >= 1024 && index < units.length - 1) { value /= 1024; index += 1; }
  return `${value.toLocaleString(undefined, { maximumFractionDigits: index >= 3 ? 2 : 0 })} ${units[index]}`;
}

function AdminCronJobsDialog({ api, alertAction = null, onClose }) {
  const [payload, setPayload] = useState(null);
  const [content, setContent] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const jobs = useMemo(() => parseCronPreviewJobs(content), [content]);
  const websiteTasks = Array.isArray(payload?.scheduled_tasks) ? payload.scheduled_tasks : [];
  const hasScheduler = content.includes('artisan schedule:run');

  const loadCronJobs = useCallback(async () => {
    if (!api?.request) {
      setError('Admin API client is unavailable.');
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    try {
      const data = await api.request('/admin/cron-jobs');
      setPayload(data || null);
      setContent(data?.content || '');
    } catch (requestError) {
      setError(requestError.message || 'Cron jobs could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    loadCronJobs();
  }, [loadCronJobs]);

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape' && !saving) onClose();
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose, saving]);

  const normalizedLines = (value) => String(value || '').replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n');
  const writeLines = (lines) => {
    const next = lines.join('\n').replace(/\n*$/, '');
    return next ? `${next}\n` : '';
  };
  const updateLine = (lineNumber, updater) => {
    setContent((current) => {
      const lines = normalizedLines(current);
      const index = Number(lineNumber) - 1;
      if (index < 0 || index >= lines.length) return current;
      const nextValue = updater(lines[index]);
      if (nextValue === null) lines.splice(index, 1);
      else lines[index] = nextValue;
      return writeLines(lines);
    });
  };
  const insertScheduler = () => {
    const line = payload?.recommended_line;
    if (!line || content.includes('artisan schedule:run')) return;
    setContent((current) => `${String(current || '').trimEnd()}${String(current || '').trim() ? '\n' : ''}${line}\n`);
  };
  const saveCronJobs = async (event) => {
    event.preventDefault();
    if (!api?.request || saving) return;
    setSaving(true);
    setError('');
    try {
      const data = await api.request('/admin/cron-jobs', { method: 'PUT', body: JSON.stringify({ content }) });
      setPayload(data || null);
      setContent(data?.content || '');
      void alertAction?.({ title: 'Cron jobs saved', message: data?.message || 'Cron jobs saved.' });
    } catch (requestError) {
      const message = requestError.message || 'Cron jobs could not be saved.';
      setError(message);
      void alertAction?.({ title: 'Cron save failed', message, tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !saving && onClose()}>
      <form className="admin-edit-user-dialog admin-cron-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-cron-title" onSubmit={saveCronJobs}>
        <div className="admin-edit-user-head">
          <span className="admin-confirm-icon"><AdminIcon name="calendar" size={22} /></span>
          <span>
            <h2 id="admin-cron-title">Manage Cron Jobs</h2>
            <p>Review the website scheduler tasks and manage the active crontab for this server user.</p>
          </span>
        </div>
        {error && <p className="admin-edit-error">{error}</p>}
        {loading ? <div className="state-card">Loading cron jobs...</div> : <>
          <div className="admin-cron-status-row">
            <span className={`admin-tag tone-${hasScheduler ? 'green' : 'orange'}`}>{hasScheduler ? 'Laravel scheduler installed' : 'Laravel scheduler missing'}</span>
            <span className="admin-cron-count">Server user: {payload?.user || 'web server user'}</span>
            <span className="admin-cron-count">{websiteTasks.length} website task{websiteTasks.length === 1 ? '' : 's'}</span>
            <span className="admin-cron-count">{jobs.length} server cron line{jobs.length === 1 ? '' : 's'}</span>
          </div>
          <div className="admin-cron-actions">
            <button type="button" className="admin-confirm-button cancel" onClick={insertScheduler} disabled={saving || !payload?.recommended_line || content.includes('artisan schedule:run')}>Install Laravel Scheduler</button>
            <button type="button" className="admin-confirm-button cancel" onClick={loadCronJobs} disabled={saving}>Reload</button>
          </div>
          <div className="admin-cron-section-title"><span>Website scheduled tasks</span><small>Defined by Laravel and triggered by the scheduler cron line.</small></div>
          <div className="admin-cron-list admin-cron-task-list" aria-label="Website scheduled tasks">
            {websiteTasks.length ? websiteTasks.map((task, index) => (
              <div className="admin-cron-job admin-cron-task" key={`${task.name || 'task'}-${index}`}>
                <span>
                  <strong>{task.name || `Scheduled task ${index + 1}`}</strong>
                  <small>{task.command || 'Laravel callback'}</small>
                </span>
                <div>
                  <em>{task.schedule || '-'}</em>
                  {task.without_overlapping ? <b>No overlap</b> : null}
                </div>
              </div>
            )) : <p className="admin-cron-empty">No website scheduler tasks were found.</p>}
          </div>
          <div className="admin-cron-section-title"><span>Server crontab</span><small>This should include one Laravel scheduler line.</small></div>
          <div className="admin-cron-list" aria-label="Current cron jobs">
            {jobs.length ? jobs.map((job) => (
              <div className="admin-cron-job" key={`${job.line_number}-${job.command}`}>
                <span>
                  <strong>{job.schedule}</strong>
                  <small>{job.command}</small>
                </span>
                <div>
                  <button type="button" onClick={() => updateLine(job.line_number, (line) => job.enabled ? `# ${line}` : line.replace(/^\s*#\s?/, ''))} disabled={saving}>{job.enabled ? 'Disable' : 'Enable'}</button>
                  <button type="button" className="danger" onClick={() => updateLine(job.line_number, () => null)} disabled={saving}>Remove</button>
                </div>
              </div>
            )) : <p className="admin-cron-empty">No cron jobs are installed for this user.</p>}
          </div>
          <label className="admin-edit-field">
            <span>Crontab Content</span>
            <textarea className="admin-cron-editor" value={content} onChange={(event) => setContent(event.target.value)} placeholder="* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1" disabled={saving} spellCheck="false" />
            <small>Comments and environment variables are preserved. Saving replaces the active crontab for this server user.</small>
          </label>
        </>}
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={onClose} disabled={saving}>Cancel</button>
          <button type="submit" className="admin-confirm-button primary" disabled={loading || saving}>{saving ? 'Saving...' : 'Save Cron Jobs'}</button>
        </div>
      </form>
    </div>
  );
}

function parseCronPreviewJobs(content = '') {
  const jobs = [];
  String(content || '').replace(/\r\n?/g, '\n').split('\n').forEach((line, index) => {
    let trimmed = line.trim();
    let enabled = true;
    if (trimmed.startsWith('#')) {
      enabled = false;
      trimmed = trimmed.slice(1).trim();
    }
    if (!trimmed || /^[A-Z_][A-Z0-9_]*\s*=/i.test(trimmed)) return;
    const macro = trimmed.match(/^(@(?:reboot|yearly|annually|monthly|weekly|daily|hourly))\s+(.+)$/i);
    const standard = macro || trimmed.match(/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/);
    if (!standard) return;
    jobs.push({ line_number: index + 1, schedule: standard[1].trim(), command: standard[2].trim(), enabled });
  });
  return jobs;
}

function AdminPromptDialog({ dialog, onClose }) {
  const isInvite = dialog.type === 'invite';
  const isMassImport = dialog.type === 'mass-import';
  const isBulkCategory = dialog.type === 'bulk-category';
  const isManageCategory = dialog.type === 'manage-category';
  const isAnnouncement = dialog.type === 'announcement';
  const isRole = dialog.type === 'role';
  const isPermissionGroup = dialog.type === 'permission-group';
  const isWalletCredit = dialog.type === 'wallet-credit';
  const isLicense = dialog.type === 'license';
  const massProviders = dialog.providers || [];
  const massCategories = dialog.categories || [];
  const contentCategories = dialog.categories || [];
  const permissionGroups = dialog.permissionGroups || [];
  const permissionCatalog = dialog.permissionCatalog || [];
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [role, setRole] = useState(dialog.defaultRole || 'User');
  const [emails, setEmails] = useState('');
  const [announcementSubject, setAnnouncementSubject] = useState('');
  const [announcementMessage, setAnnouncementMessage] = useState('');
  const [roleName, setRoleName] = useState(dialog.role?.name || '');
  const [roleDescription, setRoleDescription] = useState(dialog.role?.desc || '');
  const [roleScope, setRoleScope] = useState(dialog.role?.scope || 'Restricted');
  const [roleGroupId, setRoleGroupId] = useState(dialog.role?.group_id || permissionGroups[0]?.id || '');
  const [permissionGroupId, setPermissionGroupId] = useState(dialog.defaultGroupId || '');
  const [permissionGroupName, setPermissionGroupName] = useState('');
  const [permissionGroupDescription, setPermissionGroupDescription] = useState('');
  const [selectedPermissions, setSelectedPermissions] = useState(permissionCatalog[0] ? [permissionCatalog[0]] : []);
  const [walletAmount, setWalletAmount] = useState('');
  const [walletNote, setWalletNote] = useState('');
  const [licenseKey, setLicenseKey] = useState('');
  const [categoryId, setCategoryId] = useState(String(dialog.defaultCategoryId ?? contentCategories[0]?.id ?? ''));
  const [manageCategoryId, setManageCategoryId] = useState('');
  const [categoryDeletePending, setCategoryDeletePending] = useState(false);
  const [categoryName, setCategoryName] = useState('');
  const [categorySortOrder, setCategorySortOrder] = useState('');
  const [massProvider, setMassProvider] = useState(dialog.defaultProvider || massProviders[0]?.key || '');
  const [massUrls, setMassUrls] = useState('');
  const [massKeyword, setMassKeyword] = useState('');
  const [massCategory, setMassCategory] = useState(massCategories[0]?.id || '');
  const [massVisibility, setMassVisibility] = useState('public');
  const [massCount, setMassCount] = useState('10');
  const [massTags, setMassTags] = useState('');
  const [massMadeForKids, setMassMadeForKids] = useState(false);
  const [massMonetization, setMassMonetization] = useState(false);
  const [massSkipDuplicates, setMassSkipDuplicates] = useState(true);
  const [massStopOnError, setMassStopOnError] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [progress, setProgress] = useState(0);
  const [progressText, setProgressText] = useState('');
  const massUrlCount = parseMassImportUrls(massUrls).length;
  const massImportCount = Math.max(1, Math.min(50, Number(massCount) || 1));
  const selectedManageCategory = contentCategories.find((item) => String(item.id) === manageCategoryId);

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape' && !processing) onClose(null);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose, processing]);

  useEffect(() => {
    if (!isManageCategory) return;
    const selectedCategory = contentCategories.find((item) => String(item.id) === manageCategoryId);
    setCategoryName(selectedCategory?.name || '');
    setCategorySortOrder(selectedCategory?.sort_order ?? '');
    setCategoryDeletePending(false);
  }, [contentCategories, isManageCategory, manageCategoryId]);

  useEffect(() => {
    if (!isPermissionGroup) return;
    const selectedGroup = permissionGroups.find((item) => String(item.id) === permissionGroupId);
    setPermissionGroupName(selectedGroup?.name || '');
    setPermissionGroupDescription(selectedGroup?.description || '');
    setSelectedPermissions(selectedGroup?.permissions?.length ? selectedGroup.permissions : (permissionCatalog[0] ? [permissionCatalog[0]] : []));
  }, [isPermissionGroup, permissionCatalog, permissionGroupId, permissionGroups]);

  const togglePermission = (permission, checked) => {
    setSelectedPermissions((current) => checked ? Array.from(new Set([...current, permission])) : current.filter((item) => item !== permission));
  };

  const submit = async (event) => {
    event.preventDefault();
    if (processing || categoryDeletePending) return;
    let payload;
    if (isInvite) {
      const safeEmail = email.trim();
      payload = { email: safeEmail, name: name.trim() || safeEmail.split('@')[0] || '', role };
    } else if (isMassImport) {
      payload = { provider: massProvider, urls: parseMassImportUrls(massUrls), keyword: massKeyword.trim(), count: massImportCount, category_id: massCategory || null, visibility: massVisibility, tags: massTags.trim(), made_for_kids: massMadeForKids, monetization_enabled: massMonetization, skip_duplicates: massSkipDuplicates, stop_on_error: massStopOnError };
    } else if (isBulkCategory) {
      payload = { category_id: categoryId === '' ? null : Number(categoryId) };
    } else if (isManageCategory) {
      payload = { id: manageCategoryId ? Number(manageCategoryId) : null, name: categoryName.trim(), sort_order: categorySortOrder === '' ? null : Number(categorySortOrder) };
    } else if (isAnnouncement) {
      payload = { subject: announcementSubject.trim(), message: announcementMessage.trim() };
    } else if (isRole) {
      payload = { id: dialog.role?.id || null, name: roleName.trim(), description: roleDescription.trim(), scope: roleScope, group_id: roleGroupId };
    } else if (isPermissionGroup) {
      payload = { id: permissionGroupId || null, name: permissionGroupName.trim(), description: permissionGroupDescription.trim(), permissions: selectedPermissions };
    } else if (isWalletCredit) {
      payload = { amount: Number(walletAmount), note: walletNote.trim() || null };
    } else if (isLicense) {
      payload = { license_key: licenseKey.trim() };
    } else {
      payload = { emails: emails.trim() };
    }

    if (!dialog.onSubmit) {
      onClose(payload);
      return;
    }

    if (isMassImport) {
      try {
        dialog.onSubmit(payload);
        onClose({ started: true });
      } catch (error) {
        await dialog.onError?.(error);
      }
      return;
    }

    setProcessing(true);
    setProgress(8);
    setProgressText('Processing...');
    let currentProgress = 8;
    const timer = isMassImport ? null : window.setInterval(() => {
      currentProgress = Math.min(94, currentProgress + Math.max(2, Math.round((94 - currentProgress) * .18)));
      setProgress(currentProgress);
      setProgressText(currentProgress < 38 ? 'Submitting request...' : currentProgress < 72 ? 'Updating records...' : 'Finalizing...');
    }, 350);

    try {
      const result = await dialog.onSubmit(payload, { setProgress, setProgressText });
      if (timer) window.clearInterval(timer);
      setProgress(100);
      setProgressText('Completed');
      window.setTimeout(() => onClose(result || { completed: true }), 300);
    } catch (error) {
      if (timer) window.clearInterval(timer);
      setProcessing(false);
      setProgress(0);
      setProgressText('');
      await dialog.onError?.(error);
    }
  };

  const promptSubmitLabel = processing ? (dialog.processingLabel || 'Processing...') : (isManageCategory && manageCategoryId ? 'Save Category' : (dialog.submitLabel || 'Continue'));

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !processing && onClose(null)}>
      <form className={`admin-edit-user-dialog${isManageCategory ? ' admin-category-dialog' : ''}`} role="dialog" aria-modal="true" aria-labelledby="admin-prompt-title" onSubmit={submit}>
        <div className="admin-edit-user-head">
          <span className="admin-confirm-icon"><AdminIcon name={isMassImport ? 'cloud' : isInvite ? 'userPlus' : isBulkCategory || isManageCategory ? 'content' : isRole || isPermissionGroup ? 'shield' : isWalletCredit ? 'monetization' : isLicense ? 'key' : 'mail'} size={22} /></span>
          <span>
            <h2 id="admin-prompt-title">{dialog.title || 'Enter details'}</h2>
            <p>{dialog.description || 'Complete the fields below to continue.'}</p>
          </span>
        </div>
        {isLicense ? <label className="admin-edit-field">
          <span>{dialog.inputLabel || 'License key or purchase code'}</span>
          <input value={licenseKey} onChange={(event) => setLicenseKey(event.target.value)} maxLength={48} autoComplete="off" spellCheck="false" placeholder={dialog.placeholder || 'KNL-XXXX-... or Envato purchase code'} autoFocus required />
          <small>Supports PlayHub store licenses and Envato CodeCanyon purchase codes.</small>
        </label> : isInvite ? <>
          <label className="admin-edit-field">
            <span>Email</span>
            <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
          </label>
          <label className="admin-edit-field">
            <span>Name</span>
            <input value={name} onChange={(event) => setName(event.target.value)} placeholder="Optional, defaults from email" />
          </label>
          <label className="admin-edit-field">
            <span>Role</span>
            <AdaptiveSelect value={role} onChange={(event) => setRole(event.target.value)} required>
              {(dialog.roleOptions || ['User', 'Creator', 'Moderator', 'Admin', 'Super Admin', 'Owner']).map((option) => <option value={option} key={option}>{option}</option>)}
            </AdaptiveSelect>
          </label>
        </> : isMassImport ? <>
          <label className="admin-edit-field">
            <span>Provider</span>
            <AdaptiveSelect value={massProvider} onChange={(event) => setMassProvider(event.target.value)} disabled={processing || !massProviders.length} required>
              {massProviders.length ? massProviders.map((provider) => <option key={provider.key} value={provider.key}>{provider.label}</option>) : <option value="">No enabled providers</option>}
            </AdaptiveSelect>
            {!massProviders.length && <small>Enable providers in System Settings first.</small>}
          </label>
          <label className="admin-edit-field">
            <span>Video URLs (optional)</span>
            <textarea value={massUrls} onChange={(event) => setMassUrls(event.target.value)} placeholder="https://www.youtube.com/watch?v=...&#10;https://vimeo.com/..." disabled={processing} />
            <small>{massUrlCount ? `${massUrlCount} URL${massUrlCount === 1 ? '' : 's'} ready. Count limits how many are imported.` : 'Leave empty to discover videos from the selected provider, keyword, or category.'}</small>
          </label>
          <div className="admin-mass-import-grid">
            <label className="admin-edit-field">
              <span>Keyword</span>
              <input value={massKeyword} onChange={(event) => setMassKeyword(event.target.value)} placeholder="Optional search keyword" disabled={processing} />
            </label>
            <label className="admin-edit-field">
              <span>Category</span>
              <AdaptiveSelect value={massCategory} onChange={(event) => setMassCategory(event.target.value)} disabled={processing}>
                <option value="">No category</option>
                {massCategories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </AdaptiveSelect>
            </label>
            <label className="admin-edit-field">
              <span>Import count</span>
              <input type="number" min="1" max="50" value={massCount} onChange={(event) => setMassCount(event.target.value)} disabled={processing} required />
            </label>
            <label className="admin-edit-field">
              <span>Visibility</span>
              <AdaptiveSelect value={massVisibility} onChange={(event) => setMassVisibility(event.target.value)} disabled={processing}>
                <option value="public">Public</option>
                <option value="unlisted">Unlisted</option>
                <option value="private">Private</option>
              </AdaptiveSelect>
            </label>
          </div>
          <label className="admin-edit-field">
            <span>Extra tags</span>
            <input value={massTags} onChange={(event) => setMassTags(event.target.value)} placeholder="Comma separated, optional" disabled={processing} />
          </label>
          <label className="admin-edit-check"><input type="checkbox" checked={massMadeForKids} onChange={(event) => setMassMadeForKids(event.target.checked)} disabled={processing} /><span>Mark imported videos as made for kids</span></label>
          <label className="admin-edit-check"><input type="checkbox" checked={massMonetization} onChange={(event) => setMassMonetization(event.target.checked)} disabled={processing} /><span>Enable monetization on imported videos</span></label>
          <label className="admin-edit-check"><input type="checkbox" checked={massSkipDuplicates} onChange={(event) => setMassSkipDuplicates(event.target.checked)} disabled={processing} /><span>Skip duplicate URLs in this batch</span></label>
          <label className="admin-edit-check"><input type="checkbox" checked={massStopOnError} onChange={(event) => setMassStopOnError(event.target.checked)} disabled={processing} /><span>Stop on first failed import</span></label>
        </> : isBulkCategory ? <>
          <label className="admin-edit-field">
            <span>Category</span>
            <AdaptiveSelect value={categoryId} onChange={(event) => setCategoryId(event.target.value)} disabled={processing}>
              <option value="">Uncategorized</option>
              {contentCategories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </AdaptiveSelect>
            {dialog.scopeLabel && <small>{dialog.scopeLabel}</small>}
          </label>
        </> : isManageCategory ? <>
          <label className="admin-edit-field">
            <span>Current categories</span>
            <AdaptiveSelect value={manageCategoryId} onChange={(event) => setManageCategoryId(event.target.value)} disabled={processing || categoryDeletePending}>
              <option value="">Add new category</option>
              {contentCategories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </AdaptiveSelect>
            {!contentCategories.length && <small>No content categories are configured yet.</small>}
          </label>
          <label className="admin-edit-field">
            <span>Category name</span>
            <input value={categoryName} onChange={(event) => setCategoryName(event.target.value)} placeholder="Example: Tutorials" disabled={processing || categoryDeletePending} required />
          </label>
          <label className="admin-edit-field">
            <span>Sort order</span>
            <input type="number" min="0" value={categorySortOrder} onChange={(event) => setCategorySortOrder(event.target.value)} placeholder="Auto" disabled={processing || categoryDeletePending} />
          </label>
          {categoryDeletePending && selectedManageCategory && <div className="admin-category-delete-confirmation" role="alert">
            <strong>Delete "{selectedManageCategory.name}"?</strong>
            <p>This category will be permanently removed. Its videos will be kept and marked Uncategorized. This cannot be undone.</p>
          </div>}
        </> : isAnnouncement ? <>
          <label className="admin-edit-field">
            <span>Subject</span>
            <input value={announcementSubject} onChange={(event) => setAnnouncementSubject(event.target.value)} disabled={processing} required />
          </label>
          <label className="admin-edit-field">
            <span>Message</span>
            <textarea value={announcementMessage} onChange={(event) => setAnnouncementMessage(event.target.value)} disabled={processing} required />
          </label>
        </> : isRole ? <>
          <label className="admin-edit-field">
            <span>Role name</span>
            <input value={roleName} onChange={(event) => setRoleName(event.target.value)} disabled={processing} required />
          </label>
          <label className="admin-edit-field">
            <span>Description</span>
            <input value={roleDescription} onChange={(event) => setRoleDescription(event.target.value)} disabled={processing} placeholder="What this role can do" />
          </label>
          <div className="admin-mass-import-grid">
            <label className="admin-edit-field">
              <span>Scope</span>
              <AdaptiveSelect value={roleScope} onChange={(event) => setRoleScope(event.target.value)} disabled={processing}>
                {['Global', 'Moderation', 'Content', 'Support', 'Analytics', 'Restricted'].map((option) => <option value={option} key={option}>{option}</option>)}
              </AdaptiveSelect>
            </label>
            <label className="admin-edit-field">
              <span>Permission group</span>
              <AdaptiveSelect value={roleGroupId} onChange={(event) => setRoleGroupId(event.target.value)} disabled={processing || !permissionGroups.length} required>
                {permissionGroups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
              </AdaptiveSelect>
            </label>
          </div>
        </> : isPermissionGroup ? <>
          <label className="admin-edit-field">
            <span>Current group</span>
            <AdaptiveSelect value={permissionGroupId} onChange={(event) => setPermissionGroupId(event.target.value)} disabled={processing}>
              <option value="">Add new group</option>
              {permissionGroups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
            </AdaptiveSelect>
          </label>
          <label className="admin-edit-field">
            <span>Group name</span>
            <input value={permissionGroupName} onChange={(event) => setPermissionGroupName(event.target.value)} disabled={processing} required />
          </label>
          <label className="admin-edit-field">
            <span>Description</span>
            <input value={permissionGroupDescription} onChange={(event) => setPermissionGroupDescription(event.target.value)} disabled={processing} placeholder="What this permission group controls" />
          </label>
          <div className="admin-edit-field">
            <span>Permissions</span>
            {permissionCatalog.map((permission) => (
              <label className="admin-edit-check" key={permission}>
                <input type="checkbox" checked={selectedPermissions.includes(permission)} onChange={(event) => togglePermission(permission, event.target.checked)} disabled={processing} />
                <span>{permission}</span>
              </label>
            ))}
          </div>
        </> : isWalletCredit ? <>
          <label className="admin-edit-field">
            <span>Amount</span>
            <input type="number" min="0.01" max="1000000" step="0.01" value={walletAmount} onChange={(event) => setWalletAmount(event.target.value)} placeholder="0.00" disabled={processing} autoFocus required />
          </label>
          <label className="admin-edit-field">
            <span>Note</span>
            <textarea value={walletNote} onChange={(event) => setWalletNote(event.target.value)} placeholder="Optional internal note" maxLength={500} disabled={processing} />
          </label>
        </> : <label className="admin-edit-field">
          <span>{dialog.inputLabel || 'Email addresses'}</span>
          <textarea value={emails} onChange={(event) => setEmails(event.target.value)} placeholder={dialog.placeholder || 'user@example.com, creator@example.com'} required />
        </label>}
        <div className={`admin-edit-actions${isManageCategory ? ' admin-category-actions' : ''}`}>
          {isManageCategory && categoryDeletePending ? <>
            <button type="button" className="admin-confirm-button cancel" onClick={() => setCategoryDeletePending(false)} disabled={processing}>Cancel deletion</button>
            <button type="button" className="admin-confirm-button danger" disabled={processing || !selectedManageCategory} onClick={() => {
              if (processing || !selectedManageCategory) return;
              setProcessing(true);
              onClose({ id: Number(selectedManageCategory.id), action: 'delete' });
            }}>Delete Category</button>
          </> : <>
            <button type="button" className="admin-confirm-button cancel" onClick={() => onClose(null)} disabled={processing}>Cancel</button>
            {isManageCategory && selectedManageCategory && <button type="button" className="admin-confirm-button danger" onClick={() => setCategoryDeletePending(true)} disabled={processing}>Delete Category</button>}
            <button type="submit" className="admin-confirm-button primary" disabled={processing || (isMassImport && (!massProviders.length || !massProvider || (!massUrlCount && !massCategory && !massKeyword.trim()))) || (isManageCategory && !categoryName.trim()) || (isAnnouncement && (!announcementSubject.trim() || !announcementMessage.trim())) || (isRole && (!roleName.trim() || !roleGroupId)) || (isPermissionGroup && (!permissionGroupName.trim() || !selectedPermissions.length)) || (isWalletCredit && (!Number.isFinite(Number(walletAmount)) || Number(walletAmount) < 0.01 || Number(walletAmount) > 1000000)) || (isLicense && !licenseKey.trim())}>{promptSubmitLabel}</button>
          </>}
        </div>
      </form>
    </div>
  );
}

function AdminEditUserDialog({ dialog, onClose }) {
  const row = dialog.row || {};
  const person = row.person || {};
  const canManageAdmin = dialog.canManageAdmin !== false;
  const quotaOptions = [['', 'Use default'], ['10', '10 GB'], ['25', '25 GB'], ['50', '50 GB'], ['100', '100 GB'], ['250', '250 GB'], ['500', '500 GB'], ['750', '750 GB'], ['1024', '1 TB']];
  const initialQuota = row.raw?.storage_quota_gb ?? '';
  const [name, setName] = useState(row.raw?.name || person.name || '');
  const [username, setUsername] = useState(row.raw?.username || String(person.handle || '').replace(/^@/, ''));
  const [email, setEmail] = useState(row.raw?.email || person.email || '');
  const [membership, setMembership] = useState(row.raw?.membership || 'starter');
  const [storageQuotaGb, setStorageQuotaGb] = useState(initialQuota);
  const [manualQuota, setManualQuota] = useState(initialQuota !== '' && !quotaOptions.some(([value]) => value === String(initialQuota)));

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose(null);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  const submit = (event) => {
    event.preventDefault();
    const payload = { name: name.trim(), username: username.trim(), email: email.trim() };
    if (canManageAdmin) {
      payload.membership = membership;
      payload.storage_quota_gb = storageQuotaGb === '' ? null : Number(storageQuotaGb);
    }
    onClose(payload);
  };

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose(null)}>
      <form className="admin-edit-user-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-edit-user-title" onSubmit={submit}>
        <div className="admin-edit-user-head">
          <AdminAvatar name={person.name || name || 'User'} tone={person.tone || 'violet'} />
          <span>
            <h2 id="admin-edit-user-title">Edit user</h2>
            <p>Update account profile details for {person.name || 'this user'}.</p>
          </span>
        </div>
        <label className="admin-edit-field">
          <span>Name</span>
          <input value={name} onChange={(event) => setName(event.target.value)} required />
        </label>
        <label className="admin-edit-field">
          <span>Username</span>
          <input value={username} onChange={(event) => setUsername(event.target.value)} required />
        </label>
        <label className="admin-edit-field">
          <span>Email</span>
          <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
        </label>
        {canManageAdmin && <label className="admin-edit-field">
          <span>Membership</span>
          <AdaptiveSelect value={membership} onChange={(event) => setMembership(event.target.value)}>
            {storageMembershipOptions.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
          </AdaptiveSelect>
        </label>}
        {canManageAdmin && <label className="admin-edit-field">
          <span>Storage quota (GB)</span>
          {manualQuota ? <input type="number" min="1" max="1024" value={storageQuotaGb} onChange={(event) => setStorageQuotaGb(event.target.value)} placeholder="Use default" /> : <AdaptiveSelect value={String(storageQuotaGb)} onChange={(event) => setStorageQuotaGb(event.target.value)}>{quotaOptions.map(([value, label]) => <option value={value} key={value || 'default'}>{label}</option>)}</AdaptiveSelect>}
          <small>Uses the membership default unless a manual quota is set.</small>
        </label>}
        {canManageAdmin && <label className="admin-edit-check">
          <input type="checkbox" checked={manualQuota} onChange={(event) => { const checked = event.target.checked; setManualQuota(checked); if (!checked && !quotaOptions.some(([value]) => value === String(storageQuotaGb))) setStorageQuotaGb(''); }} />
          <span>Manual</span>
        </label>}
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={() => onClose(null)}>Cancel</button>
          <button type="submit" className="admin-confirm-button primary">Save changes</button>
        </div>
      </form>
    </div>
  );
}

function AdminPasswordUserDialog({ dialog, onClose }) {
  const row = dialog.row || {};
  const person = row.person || {};
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose(null);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  const submit = (event) => {
    event.preventDefault();
    if (password !== confirmation) {
      setError('Passwords do not match.');
      return;
    }
    onClose({ password, password_confirmation: confirmation });
  };

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose(null)}>
      <form className="admin-edit-user-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-password-user-title" onSubmit={submit}>
        <div className="admin-edit-user-head">
          <span className="admin-confirm-icon"><AdminIcon name="key" size={22} /></span>
          <span>
            <h2 id="admin-password-user-title">Change password</h2>
            <p>Set a new password for {person.name || 'this user'}.</p>
          </span>
        </div>
        <label className="admin-edit-field">
          <span>New password</span>
          <input type="password" value={password} minLength={8} onChange={(event) => { setPassword(event.target.value); setError(''); }} placeholder="At least 8 characters" autoFocus required />
        </label>
        <label className="admin-edit-field">
          <span>Confirm new password</span>
          <input type="password" value={confirmation} minLength={8} onChange={(event) => { setConfirmation(event.target.value); setError(''); }} placeholder="Repeat password" required />
          <small>The user will need the new password on their next login.</small>
        </label>
        {error && <p className="admin-edit-error">{error}</p>}
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={() => onClose(null)}>Cancel</button>
          <button type="submit" className="admin-confirm-button primary">Update password</button>
        </div>
      </form>
    </div>
  );
}

function AdminBanUserDialog({ dialog, onClose }) {
  const row = dialog.row || {};
  const person = row.person || {};
  const activeScopes = row.raw?.active_ban_scopes || [];
  const [duration, setDuration] = useState('1_day');
  const [scopes, setScopes] = useState({
    account: activeScopes.includes('account'),
    comments: activeScopes.length ? activeScopes.includes('comments') : true,
    uploads: activeScopes.includes('uploads'),
    watch: activeScopes.includes('watch'),
  });
  const selectedScopes = Object.entries(scopes).filter(([, checked]) => checked).map(([scope]) => scope);
  const durationOptions = [['1_day', '1 day'], ['1_week', '1 week'], ['2_weeks', '2 weeks'], ['1_month', '1 month']];
  const scopeOptions = [
    ['account', 'Lock account'],
    ['comments', 'Comments only'],
    ['uploads', 'Uploading videos'],
    ['watch', 'Watching videos and shorts'],
  ];

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose(null);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  const submit = (event) => {
    event.preventDefault();
    if (!selectedScopes.length) return;
    onClose({ duration, scopes: selectedScopes });
  };

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose(null)}>
      <form className="admin-edit-user-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-ban-user-title" onSubmit={submit}>
        <div className="admin-edit-user-head">
          <span className="admin-confirm-icon"><AdminIcon name="ban" size={22} /></span>
          <span>
            <h2 id="admin-ban-user-title">Ban user</h2>
            <p>Restrict account abilities for {person.name || 'this user'}.</p>
          </span>
        </div>
        <label className="admin-edit-field">
          <span>Ban duration</span>
          <AdaptiveSelect value={duration} onChange={(event) => setDuration(event.target.value)}>
            {durationOptions.map(([value, label]) => <option value={value} key={value}>{label}</option>)}
          </AdaptiveSelect>
        </label>
        <div className="admin-edit-field">
          <span>Ban scope</span>
          {scopeOptions.map(([scope, label]) => (
            <label className="admin-edit-check" key={scope}>
              <input type="checkbox" checked={scopes[scope]} onChange={(event) => setScopes((current) => ({ ...current, [scope]: event.target.checked }))} />
              <span>{label}</span>
            </label>
          ))}
        </div>
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={() => onClose(null)}>Cancel</button>
          <button type="submit" className="admin-confirm-button danger" disabled={!selectedScopes.length}>Apply ban</button>
        </div>
      </form>
    </div>
  );
}

function AdminRoleUserDialog({ dialog, onClose }) {
  const row = dialog.row || {};
  const person = row.person || {};
  const [role, setRole] = useState(() => selectedAdminUserRole(row));

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose(null);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  const submit = (event) => {
    event.preventDefault();
    onClose({ role });
  };

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose(null)}>
      <form className="admin-edit-user-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-role-user-title" onSubmit={submit}>
        <div className="admin-edit-user-head">
          <AdminAvatar name={person.name || 'User'} tone={person.tone || 'violet'} />
          <span>
            <h2 id="admin-role-user-title">Change role</h2>
            <p>Select a new access role for {person.name || 'this user'}.</p>
          </span>
        </div>
        <label className="admin-edit-field">
          <span>Role</span>
          <AdaptiveSelect value={role} onChange={(event) => setRole(event.target.value)} required>
            {(row.roleOptions || ADMIN_USER_ROLE_LABELS).map((option) => <option value={adminUserRoleValue(option)} key={option}>{option}</option>)}
          </AdaptiveSelect>
        </label>
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={() => onClose(null)}>Cancel</button>
          <button type="submit" className="admin-confirm-button primary">Change role</button>
        </div>
      </form>
    </div>
  );
}

function AdminEmailUserDialog({ dialog, onClose }) {
  const row = dialog.row || {};
  const person = row.person || {};
  const [subject, setSubject] = useState(() => `Message from ${configuredWebsiteName()}`);
  const [message, setMessage] = useState('');

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose(null);
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  const submit = (event) => {
    event.preventDefault();
    onClose({ subject: subject.trim(), message: message.trim() });
  };

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose(null)}>
      <form className="admin-edit-user-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-email-user-title" onSubmit={submit}>
        <div className="admin-edit-user-head">
          <AdminAvatar name={person.name || 'User'} tone={person.tone || 'violet'} />
          <span>
            <h2 id="admin-email-user-title">Send email</h2>
            <p>Send an admin message to {person.email || person.name || 'this user'}.</p>
          </span>
        </div>
        <label className="admin-edit-field">
          <span>Subject</span>
          <input value={subject} onChange={(event) => setSubject(event.target.value)} required />
        </label>
        <label className="admin-edit-field">
          <span>Message</span>
          <textarea value={message} onChange={(event) => setMessage(event.target.value)} required />
        </label>
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={() => onClose(null)}>Cancel</button>
          <button type="submit" className="admin-confirm-button primary">Send email</button>
        </div>
      </form>
    </div>
  );
}

function AdminSupportTicketDialog({ row, api, onClose, onRefresh, alertAction }) {
  const [ticket, setTicket] = useState(null);
  const [response, setResponse] = useState('');
  const [resolveAfterReply, setResolveAfterReply] = useState(true);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  useEffect(() => {
    let cancelled = false;
    if (!api?.request || !row?.raw_id) {
      setLoading(false);
      setError('Ticket details are unavailable.');
      return undefined;
    }
    setLoading(true);
    setError('');
    api.request(`/admin/support/contact-messages/${row.raw_id}`)
      .then((data) => {
        if (cancelled) return;
        setTicket(data?.ticket || null);
        setResponse(data?.ticket?.response || '');
      })
      .catch((err) => {
        if (!cancelled) setError(err.message || 'Unable to load ticket.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [api, row?.raw_id]);

  const submitResponse = async (event) => {
    event.preventDefault();
    const message = response.trim();
    if (!message || !row?.raw_id || !api?.request) return;
    setBusy('reply');
    setError('');
    try {
      const data = await api.request(`/admin/support/contact-messages/${row.raw_id}/reply`, { method: 'POST', body: JSON.stringify({ response: message, resolve: resolveAfterReply }) });
      if (data?.ticket) setTicket(data.ticket);
      onRefresh?.();
      await alertAction?.({ title: 'Support ticket', message: data?.message || 'Support response saved.' });
      if (resolveAfterReply) onClose();
    } catch (err) {
      setError(err.message || 'Unable to save response.');
    } finally {
      setBusy('');
    }
  };

  const resolveTicket = async () => {
    if (!row?.raw_id || !api?.request) return;
    setBusy('resolve');
    setError('');
    try {
      const data = await api.request(`/admin/support/contact-messages/${row.raw_id}/resolve`, { method: 'POST' });
      onRefresh?.();
      await alertAction?.({ title: 'Support ticket', message: data?.message || 'Support message resolved.' });
      onClose();
    } catch (err) {
      setError(err.message || 'Unable to resolve ticket.');
    } finally {
      setBusy('');
    }
  };

  const current = ticket || row || {};
  const user = current.user || row.user || {};

  return (
    <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <form className="admin-edit-user-dialog admin-support-ticket-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-support-ticket-title" onSubmit={submitResponse}>
        <div className="admin-edit-user-head">
          <span className="admin-confirm-icon"><AdminIcon name="support" size={22} /></span>
          <span>
            <h2 id="admin-support-ticket-title">{current.id || row.id || 'Support ticket'}</h2>
            <p>{current.subject || row.subject || 'Support request'}</p>
          </span>
        </div>
        {loading ? <div className="state-card">Loading ticket...</div> : (
          <>
            <div className="admin-ticket-meta-grid">
              <div><span>Status</span><StatusPill label={current.status || current.sla || 'Open'} /></div>
              <div><span>Priority</span><PriorityPill label={current.priority || 'Normal'} /></div>
              <div><span>Category</span><Tag label={current.category || row.category || 'Contact'} tone="slate" /></div>
              <div><span>Submitted</span><strong>{current.submitted_at || current.updated || row.updated || 'now'}</strong></div>
            </div>
            <div className="admin-ticket-person-row">
              <PersonCell person={user} mini showEmail />
              {current.ip_address && <small>{current.ip_address}</small>}
            </div>
            <section className="admin-ticket-message-box">
              <span>User message</span>
              <p>{current.message || row.preview || 'No message body available.'}</p>
            </section>
            {current.response && <section className="admin-ticket-message-box admin-ticket-response-box">
              <span>Latest admin response{current.responded_at ? ` · ${current.responded_at}` : ''}</span>
              <p>{current.response}</p>
              {current.responded_by && <small>Responded by {current.responded_by.name}</small>}
            </section>}
            <label className="admin-edit-field">
              <span>Response</span>
              <textarea value={response} onChange={(event) => setResponse(event.target.value)} placeholder="Write the reply that will be saved and emailed to the user..." required />
            </label>
            <label className="admin-ticket-check">
              <input type="checkbox" checked={resolveAfterReply} onChange={(event) => setResolveAfterReply(event.target.checked)} />
              <span>Mark ticket resolved after sending</span>
            </label>
          </>
        )}
        {error && <div className="admin-ticket-error">{error}</div>}
        <div className="admin-edit-actions">
          <button type="button" className="admin-confirm-button cancel" onClick={onClose} disabled={Boolean(busy)}>Cancel</button>
          <button type="button" className="admin-confirm-button cancel" onClick={resolveTicket} disabled={loading || Boolean(busy)}>{busy === 'resolve' ? 'Resolving...' : 'Resolve'}</button>
          <button type="submit" className="admin-confirm-button primary" disabled={loading || busy === 'reply'}>{busy === 'reply' ? 'Sending...' : 'Send response'}</button>
        </div>
      </form>
    </div>
  );
}

function getPageKey(path) {
  if (path.includes('/admin/notifications')) return 'notifications';
  if (path.includes('/admin/users')) return 'users';
  if (path.includes('/admin/creators')) return 'creators';
  if (path.includes('/admin/content')) return 'content';
  if (path.includes('/admin/live-streams')) return 'live';
  if (path.includes('/admin/gifts')) return 'gifts';
  if (path.includes('/admin/stickers') || path.includes('/admin/super-stickers')) return 'stickers';
  if (path.includes('/admin/ads')) return 'ads';
  if (path.includes('/admin/reports')) return 'reports';
  if (path.includes('/admin/monetization')) return 'monetization';
  if (path.includes('/admin/analytics')) return 'analytics';
  if (path.includes('/admin/support')) return 'support';
  if (path.includes('/admin/roles-permissions')) return 'roles';
  if (path.includes('/admin/website-configuration')) return 'website';
  if (path.includes('/admin/pages')) return 'sitePages';
  if (path.includes('/admin/seo')) return 'seo';
  if (path.includes('/admin/email-templates')) return 'emailTemplates';
  if (path.includes('/admin/languages')) return 'languages';
  if (path.includes('/admin/plugins')) return 'plugins';
  if (path.includes('/admin/system-updates')) return 'updates';
  if (path.includes('/admin/system-settings')) return 'settings';
  return 'dashboard';
}

function getPageActions(pageKey) {
  const commonExport = { label: 'Export Report', icon: 'download', variant: 'secondary' };
  const map = {
    analytics: [commonExport],
    support: [],
    roles: [{ label: 'Export Roles', icon: 'download', variant: 'secondary' }, { label: 'Invite Admin', icon: 'userPlus', variant: 'primary', split: true }],
    website: [],
    sitePages: [],
    seo: [],
    emailTemplates: [],
    plugins: [],
    updates: [],
    settings: [],
    live: [{ label: 'Export Streams', icon: 'download', variant: 'secondary' }, { label: 'Go Live', icon: 'live', variant: 'primary', split: true }],
    reports: [{ label: 'Export Reports', icon: 'download', variant: 'secondary' }, { label: 'Bulk Actions', icon: 'userPlus', variant: 'primary', split: true }],
    monetization: [commonExport, { label: 'Payout Settings', icon: 'users', variant: 'primary', split: true }],
    users: [{ label: 'Export Users', icon: 'download', variant: 'secondary' }, { label: 'Invite User', icon: 'userPlus', variant: 'primary', split: true }],
    creators: [{ label: 'Export Creators', icon: 'download', variant: 'secondary' }, { label: 'Invite Creator', icon: 'userPlus', variant: 'primary', split: true }],
    content: [{ label: 'Export Content', icon: 'download', variant: 'secondary' }, { label: 'Mass Import', icon: 'cloud', variant: 'secondary' }, { label: 'Upload Content', icon: 'userPlus', variant: 'primary', split: true }],
    dashboard: [commonExport],
  };
  return map[pageKey] || [];
}

function renderAdminPage(pageKey, navigate, data = null, api = null, onRefresh = () => {}, confirmAction = null, editUserAction = null, passwordUserAction = null, roleUserAction = null, emailUserAction = null, banUserAction = null, alertAction = null, promptAction = null, routeSearch = '', canManageAdmin = true, onNotificationsChange = null, cronJobsAction = null) {
  const pages = data?.pages || {};
  const pageMap = {
    dashboard: <DashboardPage navigate={navigate} data={pages.dashboard} api={api} onRefresh={onRefresh} alertAction={alertAction} canManageAdmin={canManageAdmin} />,
    notifications: <NotificationsPage navigate={navigate} api={api} routeSearch={routeSearch} onNotificationsChange={onNotificationsChange} />,
    analytics: <AnalyticsPage navigate={navigate} data={pages.analytics} api={api} alertAction={alertAction} />,
    support: <SupportPage navigate={navigate} data={pages.support} api={api} onRefresh={onRefresh} alertAction={alertAction} routeSearch={routeSearch} />,
    roles: <RolesPage navigate={navigate} data={pages.roles} api={api} onRefresh={onRefresh} confirmAction={confirmAction} roleUserAction={roleUserAction} alertAction={alertAction} promptAction={promptAction} />,
    website: <WebsiteConfigurationPage navigate={navigate} data={pages.website} api={api} onRefresh={onRefresh} alertAction={alertAction} confirmAction={confirmAction} />,
    sitePages: <SitePagesPage api={api} confirmAction={confirmAction} alertAction={alertAction} />,
    seo: <SeoPage data={pages.seo} api={api} onRefresh={onRefresh} alertAction={alertAction} confirmAction={confirmAction} />,
    settings: <SettingsPage navigate={navigate} data={pages.settings} api={api} onRefresh={onRefresh} alertAction={alertAction} confirmAction={confirmAction} routeSearch={routeSearch} cronJobsAction={cronJobsAction} />,
    live: <LiveStreamsPage navigate={navigate} data={pages.live} api={api} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} routeSearch={routeSearch} />,
    reports: <ReportsPage navigate={navigate} data={pages.reports} api={api} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} routeSearch={routeSearch} canManageAdmin={canManageAdmin} />,
    monetization: <MonetizationPage navigate={navigate} data={pages.monetization} api={api} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} />,
    users: <UsersPage navigate={navigate} data={pages.users} api={api} onRefresh={onRefresh} confirmAction={confirmAction} editUserAction={editUserAction} passwordUserAction={passwordUserAction} roleUserAction={roleUserAction} emailUserAction={emailUserAction} banUserAction={banUserAction} alertAction={alertAction} promptAction={promptAction} routeSearch={routeSearch} canManageAdmin={canManageAdmin} />,
    creators: <CreatorsPage navigate={navigate} data={pages.creators} api={api} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} promptAction={promptAction} routeSearch={routeSearch} />,
    content: <ContentPage navigate={navigate} data={pages.content} api={api} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} promptAction={promptAction} routeSearch={routeSearch} canManageAdmin={canManageAdmin} />,
    plugins: <PluginsPage api={api} confirmAction={confirmAction} alertAction={alertAction} />,
    updates: <SystemUpdatesPage api={api} confirmAction={confirmAction} alertAction={alertAction} promptAction={promptAction} />,
    emailTemplates: <EmailTemplatesPage api={api} confirmAction={confirmAction} alertAction={alertAction} />,
    languages: <LanguagesPage api={api} confirmAction={confirmAction} alertAction={alertAction} />,
    gifts: <GiftsPage api={api} confirmAction={confirmAction} alertAction={alertAction} />,
    stickers: <SuperStickersPage api={api} confirmAction={confirmAction} alertAction={alertAction} />,
    ads: <WebsiteAdsPage api={api} confirmAction={confirmAction} alertAction={alertAction} />,
  };
  return pageMap[pageKey] || pageMap.dashboard;
}

function PageHeader({ title, subtitle, actions = [], onAction = null }) {
  return (
    <section className="admin-page-head">
      <div>
        <h1>{title}</h1>
        <p>{subtitle}</p>
      </div>
      {!!actions.length && <div className="admin-page-actions">
        {actions.map((action) => <ActionButton action={action} key={action.label} onClick={() => onAction?.(action)} />)}
      </div>}
    </section>
  );
}

function ActionButton({ action, onClick = null }) {
  return (
    <button type="button" className={`admin-action-button ${action.variant === 'primary' ? 'primary' : ''} ${action.split ? 'split' : ''}`} onClick={onClick}>
      <AdminIcon name={action.icon} size={17} />
      <span>{action.label}</span>
      {(action.caret || action.split) && <AdminIcon name="chevronDown" size={15} />}
    </button>
  );
}

function AdminAccountDropdown({ avatarUrl, onGo, onLanguage, onSignOut, userHandle, userName }) {
  const rawHandle = String(userHandle || userName);
  const handle = rawHandle.includes('@') ? rawHandle : `@${rawHandle.replace(/\s+/g, '').toLowerCase()}`;
  const menuItems = [
    ['globe', 'Homepage', '/'],
    ['creators', 'Creator Studio', '/studio'],
    ['cloud', 'Upload video', '/upload'],
    ['globe', 'Language', null, onLanguage],
  ];

  return (
    <div className="admin-account-dropdown" role="menu" aria-label="Account menu">
      <div className="admin-account-dropdown-head">
        <AdminAvatar name={userName} image={avatarUrl} tone="orange" />
        <span>
          <strong>{userName}</strong>
          <small>{handle}</small>
        </span>
      </div>

      <div className="admin-account-dropdown-section">
        {menuItems.map(([icon, label, to, action]) => <button type="button" role="menuitem" key={label} onClick={() => action ? action() : onGo(to)}>
          <AdminIcon name={icon} size={18} />
          <span>{label}</span>
          <AdminIcon name="chevronRight" size={15} />
        </button>)}
      </div>

      <div className="admin-account-dropdown-section">
        <button type="button" role="menuitem" className="danger" onClick={onSignOut}>
          <AdminIcon name="logout" size={18} />
          <span>Logout</span>
        </button>
      </div>
    </div>
  );
}

function AdminSearchDropdown({ query, results = [], loading = false, onGo }) {
  return (
    <div className="admin-header-dropdown admin-search-dropdown" role="listbox" aria-label="Admin search results">
      <div className="admin-header-dropdown-head">
        <strong>Search results</strong>
        <small>{loading ? 'Searching...' : `${results.length} found`}</small>
      </div>
      <div className="admin-header-dropdown-list">
        {loading && <div className="admin-dropdown-empty">Searching for "{query}"...</div>}
        {!loading && results.length === 0 && <div className="admin-dropdown-empty">No admin results found.</div>}
        {!loading && results.map((result) => (
          <button type="button" key={result.id} onClick={() => onGo(result)} role="option">
            <i><AdminIcon name={result.icon || 'search'} size={17} /></i>
            <span><strong>{result.title}</strong><small>{result.type} · {result.subtitle}</small></span>
            <AdminIcon name="chevronRight" size={14} />
          </button>
        ))}
      </div>
    </div>
  );
}

function adminRouteParam(routeSearch, key) {
  try {
    return new URLSearchParams(routeSearch || '').get(key) || '';
  } catch (_) {
    return '';
  }
}

function systemSettingsBlockFromRoute(routeSearch, targetId = '') {
  const section = adminRouteParam(routeSearch, 'section');
  return systemSettingsBlocks.find((block) => block.id === targetId)
    || systemSettingsBlocks.find((block) => block.section === section)
    || systemSettingsBlocks[0];
}

function adminRouteTarget(routeSearch) {
  return adminRouteParam(routeSearch, 'highlight') || (typeof window !== 'undefined' ? window.location.hash.replace(/^#/, '') : '');
}

function useAdminRouteTarget(routeSearch, refreshKey = '') {
  const targetId = adminRouteTarget(routeSearch);
  useEffect(() => {
    if (!targetId || typeof document === 'undefined') return undefined;
    const timer = window.setTimeout(() => {
      document.getElementById(targetId)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 120);
    return () => window.clearTimeout(timer);
  }, [targetId, refreshKey]);
  return targetId;
}

function filterAdminRows(rows, searchTerm, fieldsForRow) {
  const query = String(searchTerm || '').trim().toLowerCase();
  if (!query) return rows;
  return rows.filter((row) => fieldsForRow(row).filter(Boolean).join(' ').toLowerCase().includes(query));
}

function adminPaginationPages(page, lastPage) {
  const activePage = Math.min(Math.max(Number(page) || 1, 1), Math.max(Number(lastPage) || 1, 1));
  const maxPage = Math.max(Number(lastPage) || 1, 1);
  const pageStart = Math.max(1, Math.min(activePage - 2, Math.max(maxPage - 4, 1)));
  return Array.from({ length: Math.min(5, maxPage - pageStart + 1) }, (_, index) => String(pageStart + index));
}

function paginateAdminRows(rows, page, perPage = 25) {
  const total = rows.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const activePage = Math.min(Math.max(Number(page) || 1, 1), lastPage);
  const start = (activePage - 1) * perPage;
  return {
    rows: rows.slice(start, start + perPage),
    from: total ? start + 1 : 0,
    to: Math.min(start + perPage, total),
    total,
    activePage,
    lastPage,
    pages: adminPaginationPages(activePage, lastPage),
  };
}

function AdminNotificationsDropdown({ payload = null, loading = false, onRefresh, onRead, onReadAll, onGo }) {
  const items = Array.isArray(payload?.items) ? payload.items : [];
  const unreadCount = Number(payload?.unread_count || 0);
  const popoverRef = useRef(null);
  const grouped = {
    new: items.filter((item) => item.displayUnread ?? item.unread),
    earlier: items.filter((item) => !(item.displayUnread ?? item.unread)),
  };
  const openNotification = async (item) => {
    await onRead?.(item);
    onGo(item.to || '/admin/dashboard');
  };

  return (
    <div className="admin-header-dropdown admin-notifications-dropdown" role="menu" aria-label="Admin notifications" ref={popoverRef}>
      <div className="admin-header-dropdown-head">
        <span><strong>Notifications</strong><small>{payload?.critical_count || 0} critical · {payload?.count || 0} total</small></span>
        <div className="admin-header-dropdown-actions">
          <button type="button" onClick={onReadAll} disabled={!unreadCount}><AdminIcon name="verified" size={15} />Mark all as read</button>
          <button type="button" onClick={onRefresh} disabled={loading}><AdminIcon name="refresh" size={15} />{loading ? 'Refreshing' : 'Refresh'}</button>
        </div>
      </div>
      <div className="admin-header-dropdown-list">
        {loading && !items.length && <div className="admin-dropdown-empty">Checking platform health...</div>}
        {!loading && !items.length && <div className="admin-dropdown-empty">No active admin notifications.</div>}
        <AdminNotificationGroup title="New" items={grouped.new} onOpen={openNotification} onVisible={onRead} visibilityRoot={popoverRef} />
        <AdminNotificationGroup title="Earlier" items={grouped.earlier} onOpen={openNotification} />
        <button type="button" className="admin-notifications-see-all" onClick={() => onGo('/admin/notifications')} role="menuitem">
          <i><AdminIcon name="bell" size={17} /></i>
          <span><strong>View all notifications</strong></span>
          <AdminIcon name="chevronRight" size={15} />
        </button>
      </div>
    </div>
  );
}

function AdminNotificationGroup({ title, items, onOpen, onVisible = null, visibilityRoot = null }) {
  if (!items.length) return null;

  return <div className="admin-notification-group"><h3>{title}</h3>{items.map((item) => <AdminNotificationItem key={item.id} item={item} onOpen={onOpen} onVisible={onVisible} visibilityRoot={visibilityRoot} />)}</div>;
}

function AdminNotificationItem({ item, onOpen, onVisible = null, visibilityRoot = null }) {
  const rowRef = useRef(null);
  const showUnread = item.displayUnread ?? item.unread;

  useEffect(() => {
    if (!showUnread || !onVisible) return undefined;
    const row = rowRef.current;
    if (!row) return undefined;
    if (!('IntersectionObserver' in window)) {
      onVisible(item);
      return undefined;
    }
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;
      observer.disconnect();
      onVisible(item);
    }, { root: visibilityRoot?.current || null, threshold: 0.01 });
    observer.observe(row);

    return () => observer.disconnect();
  }, [item, onVisible, showUnread, visibilityRoot]);

  return (
    <button ref={rowRef} type="button" className={`admin-notification-row tone-${item.tone || 'info'} ${showUnread ? 'unread' : ''}`} onClick={() => onOpen(item)} role="menuitem">
      <i><AdminIcon name={item.icon || 'bell'} size={17} /></i>
      <span><strong>{item.title}</strong><small>{item.message}</small><em>{item.time || 'now'}</em></span>
      {item.value !== null && item.value !== undefined ? <b>{item.value}</b> : showUnread ? <span className="admin-notification-unread-dot" /> : <span />}
    </button>
  );
}

function NotificationsPage({ navigate, api = null, routeSearch = '', onNotificationsChange = null }) {
  const routePage = Math.max(Number(adminRouteParam(routeSearch, 'page')) || 1, 1);
  const [tablePage, setTablePage] = useState(routePage);
  const [payload, setPayload] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => setTablePage(routePage), [routePage]);

  useEffect(() => {
    let cancelled = false;
    if (!api?.request) return undefined;

    const params = new URLSearchParams({ page: String(tablePage), per_page: '25' });
    setLoading(true);
    api.request(`/admin/notifications?${params.toString()}`)
      .then((data) => {
        if (cancelled) return;
        setPayload(data || null);
        if (data?.page && Number(data.page) !== tablePage) setTablePage(Number(data.page));
      })
      .catch(() => {
        if (!cancelled) setPayload((current) => current || { count: 0, critical_count: 0, unread_count: 0, items: [], page: tablePage, per_page: 25, last_page: 1, from: 0, to: 0 });
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [api, tablePage]);

  const items = Array.isArray(payload?.items) ? payload.items : [];
  const unreadCount = Number(payload?.unread_count || 0);
  const grouped = {
    new: items.filter((item) => item.displayUnread ?? item.unread),
    earlier: items.filter((item) => !(item.displayUnread ?? item.unread)),
  };
  const total = Number(payload?.count || 0);
  const lastPage = Number(payload?.last_page || 1);
  const pageStart = Math.max(1, Math.min(tablePage - 2, Math.max(lastPage - 4, 1)));
  const pages = Array.from({ length: Math.min(5, lastPage - pageStart + 1) }, (_, index) => String(pageStart + index));
  const tableFrom = Number(payload?.from ?? (total ? ((tablePage - 1) * 25) + 1 : 0));
  const tableTo = Number(payload?.to ?? Math.min(tableFrom + 24, total));
  const markLocal = useCallback((id = null, all = false) => {
    setPayload((current) => {
      if (!current) return current;
      const targetId = id === null ? null : String(id);
      let changed = 0;
      const nextItems = (current.items || []).map((item) => {
        const shouldMark = all || String(item.id) === targetId;
        const wasUnread = item.displayUnread ?? item.unread;
        if (!shouldMark || !wasUnread) return item;
        changed += 1;
        return { ...item, unread: false, displayUnread: false };
      });

      return { ...current, unread_count: all ? 0 : Math.max(0, Number(current.unread_count || 0) - changed), items: nextItems };
    });
  }, []);
  const markRead = useCallback(async (item) => {
    const isUnread = item?.displayUnread ?? item?.unread;
    if (!api?.request || !item?.id || !isUnread) return;
    markLocal(item.id);
    onNotificationsChange?.((current) => {
      if (!current) return current;
      const targetId = String(item.id);
      let changed = 1;
      const nextItems = (current.items || []).map((notification) => {
        if (String(notification.id) !== targetId) return notification;
        changed = notification.displayUnread ?? notification.unread ? 1 : 0;
        return { ...notification, unread: false, displayUnread: false };
      });

      return { ...current, unread_count: Math.max(0, Number(current.unread_count || 0) - changed), items: nextItems };
    });
    try {
      await api.request(`/admin/notifications/${encodeURIComponent(item.id)}/read`, { method: 'POST' });
    } catch (_) {}
  }, [api, markLocal, onNotificationsChange]);
  const markAllRead = useCallback(async () => {
    if (!api?.request) return;
    markLocal(null, true);
    try {
      const response = await api.request('/admin/notifications/read', { method: 'POST' });
      if (response?.notifications) onNotificationsChange?.(response.notifications);
    } catch (_) {}
  }, [api, markLocal, onNotificationsChange]);
  const openNotification = async (item) => {
    await markRead(item);
    navigate(item.to || '/admin/dashboard');
  };
  const goPage = (page) => {
    const next = Math.max(Number(page) || 1, 1);
    setTablePage(next);
    navigate(`/admin/notifications?page=${next}`);
  };

  return (
    <section className="admin-notifications-page">
      {loading && <div className="admin-table-loading">Refreshing</div>}
      <div className="admin-notifications-full-head">
        <div><strong>{unreadCount} unread</strong><span>Recent activity</span></div>
        <button type="button" onClick={markAllRead} disabled={!unreadCount}><AdminIcon name="verified" size={17} /> Mark all as read</button>
      </div>
      <div className="admin-notifications-card">
        {loading && !items.length ? <div className="admin-dropdown-empty">Checking platform health...</div> : items.length ? <>
          <AdminNotificationGroup title="New" items={grouped.new} onOpen={openNotification} onVisible={markRead} />
          <AdminNotificationGroup title="Earlier" items={grouped.earlier} onOpen={openNotification} />
        </> : <div className="admin-dropdown-empty">No active admin notifications.</div>}
      </div>
      <TableFooter text={`Showing ${tableFrom} to ${tableTo} of ${total} notifications`} pages={pages} activePage={tablePage} lastPage={lastPage} onPageChange={goPage} />
    </section>
  );
}

function AdminHelpDropdown({ pageKey, title, subtitle, onGo, onRefresh }) {
  const rows = [
    ['support', 'Current page', subtitle || `You are viewing ${title}.`, null],
    ['reports', 'Review reports', 'Open moderation reports and abuse alerts.', '/admin/reports'],
    ['settings', 'System settings', 'Configure storage, email, SMS, import, ads, login, and registration.', '/admin/system-settings'],
    ['support', 'Support queue', 'Open support tickets and system operational issues.', '/admin/support'],
  ];
  return (
    <div className="admin-header-dropdown admin-help-dropdown" role="menu" aria-label="Admin help">
      <div className="admin-header-dropdown-head">
        <strong>Help</strong>
        <small>{title}</small>
      </div>
      <div className="admin-header-dropdown-list">
        {rows.map(([icon, rowTitle, text, to]) => (
          <button type="button" key={rowTitle} onClick={() => to && onGo(to)} disabled={!to} role="menuitem">
            <i><AdminIcon name={icon} size={17} /></i>
            <span><strong>{rowTitle}</strong><small>{text}</small></span>
            {to && <AdminIcon name="chevronRight" size={14} />}
          </button>
        ))}
        <button type="button" onClick={onRefresh} role="menuitem">
          <i><AdminIcon name="refresh" size={17} /></i>
          <span><strong>Refresh admin data</strong><small>Reload metrics, reports, notifications, and system health.</small></span>
          <AdminIcon name="chevronRight" size={14} />
        </button>
      </div>
    </div>
  );
}

function DashboardPage({ navigate, data = {}, api = null, onRefresh = null, alertAction = null, canManageAdmin = true }) {
  const platformGrowthChart = data.platformGrowthChart || { title: 'Platform Growth', subtitle: 'Users', value: '0', change: '0%', yLabels: ['3', '2', '1', '0'], labels: [], series: [{ points: zeroTrend, color: 'primary', area: true }] };
  const watchTimeChart = data.watchTimeChart || { title: 'Watch Time Trend', subtitle: 'Watch Time (hours)', value: '0', change: '0%', yLabels: ['3', '2', '1', '0'], labels: [], series: [{ points: zeroTrend, color: 'primary', area: true }] };
  const runQuickAction = (action) => runAdminQuickAction(action, { navigate, api, data, alertAction });
  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid admin-dashboard-grid">
        <div className="admin-main-stack">
          <div className="admin-two-column">
            <ChartPanel {...platformGrowthChart} />
            <ChartPanel {...watchTimeChart} />
          </div>
          <TablePanel title="Recent Reports / Moderation Queue" action="View all" onAction={() => navigate('/admin/reports')}>
            <ReportsTable rows={data.reportRows || []} navigate={navigate} api={api} onRefresh={onRefresh} alertAction={alertAction} compact />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Top Creators" action="View all" onAction={() => navigate('/admin/creators')}>
            <RankedPeople rows={data.topCreators || []} />
          </SideCard>
          <SideCard title="System Health" action="View status" onAction={() => navigate('/admin/system-settings')}>
            <HealthList health={data.healthItems} />
          </SideCard>
          <QuickActionList actions={canManageAdmin ? ['Review Reports', 'Manage Roles & Permissions', 'View Analytics', 'System Settings'] : ['Review Reports']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

function AnalyticsPage({ navigate, data = {}, api = null, alertAction = null }) {
  const trafficChart = data.trafficChart || { title: 'Platform Traffic', subtitle: 'Sessions', value: '0', change: '0%', yLabels: ['3', '2', '1', '0'], labels: [], series: [{ points: zeroTrend, color: 'primary', area: true }] };
  const watchChart = data.watchChart || { title: 'Watch Time Trend', subtitle: 'Watch Time (hours)', value: '0', change: '0%', yLabels: ['3', '2', '1', '0'], labels: [], series: [{ points: zeroTrend, color: 'primary', area: true }] };
  const trafficSourceItems = data.trafficSources ? data.trafficSources.map(([label, value, color], index) => ({ label, value: String(value).split(' ')[0], note: String(value).replace(String(value).split(' ')[0], '').trim(), color, icon: ['link', 'youtube', 'google', 'facebook', 'instagram', 'more'][index] || 'more' })) : [];
  const deviceItems = data.devices ? data.devices.map(([label, value, color], index) => ({ label, value: String(value).split(' ')[0], note: String(value).replace(String(value).split(' ')[0], '').trim(), color, icon: ['desktop', 'mobile', 'tablet', 'tv'][index] || 'desktop' })) : [];
  const exportAnalyticsRows = async (rows, filename) => {
    if (rows.length) downloadRowsCsv(rows, filename);
    else await alertAction?.({ title: 'No export data', message: 'There are no rows available for this export.', tone: 'danger' });
  };
  const runQuickAction = (action) => {
    if (action === 'Export Category Analytics') return exportAnalyticsRows(data.categoryRows || [], 'playhub-top-categories.csv');
    if (action === 'Export Traffic Sources') return exportAnalyticsRows(trafficSourceItems, 'playhub-traffic-sources.csv');
    return exportAnalyticsRows(deviceItems, 'playhub-audience-devices.csv');
  };
  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid analytics-grid">
        <div className="admin-main-stack">
          <div className="admin-two-column charts-row">
            <ChartPanel {...trafficChart} />
            <ChartPanel {...watchChart} />
          </div>
          <div className="admin-two-column donuts-row">
            <DonutPanel title="Traffic Sources" items={trafficSourceItems} center={trafficChart.value} sub="Sessions" />
            <DonutPanel title="Device Breakdown" items={deviceItems} center={trafficChart.value} sub="Sessions" />
          </div>
          <TablePanel title="Top Performing Categories" action="View all" onAction={() => exportAnalyticsRows(data.categoryRows || [], 'playhub-top-categories.csv')}>
            <SimpleTable columns={['#', 'Category', 'Views', 'Watch Time (hrs)', 'Engagement Rate', 'Change']} rows={(data.categoryRows || []).map((row) => [row[0], <strong>{row[1]}</strong>, row[2], row[3], row[4], <TrendText direction={row[6]}>{row[5]}</TrendText>])} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Top Traffic Sources" action="View all" onAction={() => exportAnalyticsRows(trafficSourceItems, 'playhub-traffic-sources.csv')}><ProgressList rows={trafficSourceItems} /></SideCard>
          <SideCard title="Audience Devices" action="View all" onAction={() => exportAnalyticsRows(deviceItems, 'playhub-audience-devices.csv')}><ProgressList rows={deviceItems} /></SideCard>
          <SideCard title="Trend Insights"><InsightList items={data.insights || []} /></SideCard>
          <QuickActionList actions={['Export Category Analytics', 'Export Traffic Sources', 'Export Device Data']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

function SupportPage({ navigate, data = {}, api = null, onRefresh = () => {}, alertAction = null, routeSearch = '' }) {
  const rows = data.rows || [];
  const tabs = data.tabs || [['Open', '0'], ['In Progress', '0'], ['Waiting on User', '0'], ['Resolved', '0'], ['Escalated', '0']];
  const [activeTab, setActiveTab] = useState(tabs[0]?.[0] || 'Open');
  const routeSearchTerm = adminRouteParam(routeSearch, 'search');
  const [tableSearch, setTableSearch] = useState(routeSearchTerm);
  const [tablePage, setTablePage] = useState(1);
  const [activeTicket, setActiveTicket] = useState(null);
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [priorityFilter, setPriorityFilter] = useState('All');
  const [agentFilter, setAgentFilter] = useState('All');
  useEffect(() => setTableSearch(routeSearchTerm), [routeSearchTerm]);
  useEffect(() => setTablePage(1), [activeTab, tableSearch, categoryFilter, priorityFilter, agentFilter]);
  const highlightId = useAdminRouteTarget(routeSearch, rows.length);
  const searchedRows = filterAdminRows(rows, tableSearch, (row) => [row.id, row.subject, row.preview, row.user?.name, row.user?.email, row.category, row.priority, row.sla]);
  const filteredRows = searchedRows.filter((row) => {
    const tabMatches = activeTab === 'Escalated' ? String(row.priority).toLowerCase() === 'high' : row.sla === activeTab;
    return tabMatches
      && (categoryFilter === 'All' || row.category === categoryFilter)
      && (priorityFilter === 'All' || row.priority === priorityFilter)
      && (agentFilter === 'All' || (row.agent?.name || 'Unassigned') === agentFilter);
  });
  const tablePagination = paginateAdminRows(filteredRows, tablePage);
  const footer = `Showing ${tablePagination.from} to ${tablePagination.to} of ${tablePagination.total} tickets`;
  const agentsOnline = data.agentsOnline || [];
  const ticketCategories = data.ticketCategories || [];
  const slaItems = data.slaItems || [['On Track', '0 (0%)', '#20c56b'], ['At Risk', '0 (0%)', '#ff8a00'], ['Breached', '0 (0%)', '#ef4444']];
  const categories = ['All', ...Array.from(new Set(rows.map((row) => row.category).filter(Boolean)))];
  const priorities = ['All', ...Array.from(new Set(rows.map((row) => row.priority).filter(Boolean)))];
  const agents = ['All', ...Array.from(new Set(rows.map((row) => row.agent?.name || 'Unassigned').filter(Boolean)))];
  const resetFilters = () => { setTableSearch(''); setCategoryFilter('All'); setPriorityFilter('All'); setAgentFilter('All'); };
  const runQuickAction = async (action) => {
    if (action === 'Create Ticket') { navigate('/contact'); return; }
    if (action === 'View Open Tickets') { setActiveTab('Open'); resetFilters(); return; }
    if (action === 'High Priority Tickets') { setActiveTab('Escalated'); setPriorityFilter('High'); return; }
    if (action === 'Export Tickets') {
      if (rows.length) downloadRowsCsv(rows, 'playhub-support-tickets.csv');
      else await alertAction?.({ title: 'No support data', message: 'There are no support tickets to export.', tone: 'danger' });
    }
  };

  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid support-grid">
        <div className="admin-main-stack">
          <TablePanel flush>
            <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} />
            <FilterBar search="Search tickets by ID, subject, or user..." value={tableSearch} onSearch={setTableSearch} selects={[{ label: 'Category', value: categoryFilter, options: categories, onChange: setCategoryFilter }, { label: 'Priority', value: priorityFilter, options: priorities, onChange: setPriorityFilter }, { label: 'Agent', value: agentFilter, options: agents, onChange: setAgentFilter }]} reset onReset={resetFilters} />
            <SupportTable rows={tablePagination.rows} navigate={navigate} api={api} onRefresh={onRefresh} alertAction={alertAction} highlightId={highlightId} onOpenTicket={setActiveTicket} />
            <TableFooter text={footer} pages={tablePagination.pages} activePage={tablePagination.activePage} lastPage={tablePagination.lastPage} onPageChange={setTablePage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Agents Online" action="View all" onAction={() => navigate('/admin/roles-permissions')}><PeopleOnline rows={agentsOnline} footer={data.agentsFooter || `${agentsOnline.length} agents online`} /></SideCard>
          <SideCard title="Ticket Categories" action="View all" onAction={resetFilters}><LegendList rows={ticketCategories} /></SideCard>
          <SideCard title="SLA Overview" action="View high priority" onAction={() => runQuickAction('High Priority Tickets')}><DonutMini items={slaItems} center={data.slaCenter || tabs[0]?.[1] || '0'} sub="Total" /></SideCard>
          <QuickActionList actions={['Create Ticket', 'View Open Tickets', 'High Priority Tickets', 'Export Tickets']} onAction={runQuickAction} />
        </aside>
      </div>
      {activeTicket && <AdminSupportTicketDialog row={activeTicket} api={api} onClose={() => setActiveTicket(null)} onRefresh={onRefresh} alertAction={alertAction} />}
    </>
  );
}

function RolesPage({ navigate, data = {}, api = null, onRefresh = () => {}, confirmAction = null, roleUserAction = null, alertAction = null, promptAction = null }) {
  const rows = data.rows || data.roleRows || [];
  const permissionMatrix = data.permissionMatrix || [];
  const permissionGroups = data.permissionGroups || [];
  const [activeRoleTab, setActiveRoleTab] = useState('Roles');
  const [activeMemberTab, setActiveMemberTab] = useState('All Members');
  const [roleSearch, setRoleSearch] = useState('');
  const [memberSearch, setMemberSearch] = useState('');
  const [roleScope, setRoleScope] = useState('All');
  const [roleStatus, setRoleStatus] = useState('All');
  const [memberRole, setMemberRole] = useState('All');
  const [memberStatus, setMemberStatus] = useState('All');
  const [rolePage, setRolePage] = useState(1);
  const [memberPage, setMemberPage] = useState(1);
  useEffect(() => setRolePage(1), [activeRoleTab, roleSearch, roleScope, roleStatus]);
  useEffect(() => setMemberPage(1), [activeMemberTab, memberSearch, memberRole, memberStatus]);
  const runQuickAction = (action) => runRolesPageAction(action, { navigate, api, data, onRefresh, alertAction, promptAction, setActiveRoleTab });
  const roleScopes = ['All', ...Array.from(new Set(rows.map((row) => row.scope).filter(Boolean)))];
  const roleStatuses = ['All', ...Array.from(new Set(rows.map((row) => row.status).filter(Boolean)))];
  const roleFilterSelects = activeRoleTab === 'Permission Groups'
    ? [{ label: 'Status', value: roleStatus, options: roleStatuses, onChange: setRoleStatus }]
    : [{ label: 'Scope', value: roleScope, options: roleScopes, onChange: setRoleScope }, { label: 'Status', value: roleStatus, options: roleStatuses, onChange: setRoleStatus }];
  const filteredRoleRows = filterAdminRows(rows, roleSearch, (row) => [row.name, row.scope, row.status, row.updated]).filter((row) => (roleScope === 'All' || row.scope === roleScope) && (roleStatus === 'All' || row.status === roleStatus));
  const filteredMatrixRows = filterAdminRows(permissionMatrix, roleSearch, (row) => [row.role, row.scope, row.permissionSummary, row.status]).filter((row) => (roleScope === 'All' || row.scope === roleScope) && (roleStatus === 'All' || row.status === roleStatus));
  const filteredGroupRows = filterAdminRows(permissionGroups, roleSearch, (row) => [row.name, row.description, row.permissionSummary, row.status]).filter((row) => roleStatus === 'All' || row.status === roleStatus);
  const activeRows = activeRoleTab === 'Roles' ? filteredRoleRows : activeRoleTab === 'Permission Matrix' ? filteredMatrixRows : filteredGroupRows;
  const rolePagination = paginateAdminRows(activeRows, rolePage);
  const roleTableLabel = activeRoleTab === 'Roles' ? 'roles' : activeRoleTab === 'Permission Matrix' ? 'permission rows' : 'permission groups';
  const memberRoles = ['All', ...Array.from(new Set((data.teamRows || []).map((row) => row.role).filter(Boolean)))];
  const memberStatuses = ['All', ...Array.from(new Set((data.teamRows || []).map((row) => row.status).filter(Boolean)))];
  const roleOptions = ['User', 'Creator', 'Moderator', 'Admin', 'Super Admin', 'Owner'];
  const memberSourceRows = filterAdminRows(data.teamRows || [], memberSearch, (row) => [row.person?.name, row.person?.email, row.person?.handle, row.role, row.scope, row.status]).filter((row) => {
    const tabMatches = activeMemberTab === 'Pending Invites' ? row.status === 'Pending' : activeMemberTab === 'Deactivated' ? ['Suspended', 'Deleted'].includes(row.status) : row.status === 'Active';
    return tabMatches && (memberRole === 'All' || row.role === memberRole) && (memberStatus === 'All' || row.status === memberStatus);
  });
  const memberPagination = paginateAdminRows(memberSourceRows, memberPage);
  const teamRows = memberPagination.rows.map((row) => Array.isArray(row) ? row : [
    <PersonCell person={row.person} showEmail />,
    <Tag label={row.role} tone="blue" />,
    <Tag label={row.scope} tone={row.scope === 'Global' ? 'blue' : 'orange'} />,
    row.joined,
    <span className="admin-dot-text"><i />{row.active}</span>,
    <StatusPill label={row.status} />,
    <AdminRowActions label={row.person?.name || 'team member'} actions={[
      { label: 'Change role', icon: 'shield', onClick: () => runUserRowAction('role', { ...row, roleOptions }, api, navigate, onRefresh, confirmAction, null, null, roleUserAction, null, null, alertAction) },
      { label: row.status === 'Suspended' ? 'Reactivate member' : 'Deactivate member', icon: row.status === 'Suspended' ? 'verified' : 'pauseCircle', onClick: () => runUserRowAction('suspend', row, api, navigate, onRefresh, confirmAction, null, null, roleUserAction, null, null, alertAction) },
      { label: 'Find user', icon: 'users', onClick: () => navigate?.(`/admin/users?search=${encodeURIComponent(row.person?.email || row.person?.name || '')}`) },
      { label: 'Security settings', icon: 'shield', onClick: () => navigate?.('/admin/system-settings?section=login&highlight=setting-login#setting-login') },
    ]} />,
  ]);

  const inviteMember = async () => {
    const payload = await promptAction?.({ type: 'invite', title: 'Invite team member', description: 'Invite a user to the admin team.', defaultRole: 'Moderator', roleOptions: ['Moderator', 'Admin', 'Super Admin', 'Owner'], submitLabel: 'Invite member' });
    if (!payload) return;
    await adminJsonAction(api, '/admin/users/invite', { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
  };
  const resetRoleFilters = () => { setRoleSearch(''); setRoleScope('All'); setRoleStatus('All'); };
  const resetMemberFilters = () => { setMemberSearch(''); setMemberRole('All'); setMemberStatus('All'); };

  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid roles-grid">
        <div className="admin-main-stack">
          <TablePanel flush>
            <Tabs tabs={[['Roles'], ['Permission Matrix'], ['Permission Groups']]} active={activeRoleTab} onChange={setActiveRoleTab} />
            <FilterBar search="Search roles..." value={roleSearch} onSearch={setRoleSearch} selects={roleFilterSelects} reset onReset={resetRoleFilters} />
            {activeRoleTab === 'Roles'
              ? <RolesTable rows={rolePagination.rows} navigate={navigate} api={api} data={data} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} promptAction={promptAction} />
              : activeRoleTab === 'Permission Matrix'
                ? <PermissionMatrixTable rows={rolePagination.rows} />
                : <PermissionGroupsTable rows={rolePagination.rows} onEdit={(row) => runRolesPageAction('Manage Permission Groups', { navigate, api, data, onRefresh, alertAction, promptAction, setActiveRoleTab, defaultGroupId: row.id })} />}
            <TableFooter text={`Showing ${rolePagination.from} to ${rolePagination.to} of ${rolePagination.total} ${roleTableLabel}`} pages={rolePagination.pages} activePage={rolePagination.activePage} lastPage={rolePagination.lastPage} onPageChange={setRolePage} />
          </TablePanel>
          <TablePanel title="Team Members" actionButton="Invite Member" onAction={inviteMember}>
            <Tabs tabs={[['All Members'], ['Pending Invites'], ['Deactivated']]} active={activeMemberTab} onChange={setActiveMemberTab} subtle />
            <FilterBar search="Search members..." value={memberSearch} onSearch={setMemberSearch} selects={[{ label: 'Role', value: memberRole, options: memberRoles, onChange: setMemberRole }, { label: 'Status', value: memberStatus, options: memberStatuses, onChange: setMemberStatus }]} reset onReset={resetMemberFilters} />
            <SimpleTable columns={['Member', 'Role', 'Scope', 'Joined', 'Last Active', 'Status', 'Actions']} rows={teamRows} />
            <TableFooter text={`Showing ${memberPagination.from} to ${memberPagination.to} of ${memberPagination.total} team members`} pages={memberPagination.pages} activePage={memberPagination.activePage} lastPage={memberPagination.lastPage} onPageChange={setMemberPage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Recent Access Activity"><ActivityList rows={data.recentActivity || []} /></SideCard>
          <SideCard title="Permission Audit"><KeyValueList rows={data.auditRows || [['Permissions changed (7d)', '0'], ['Roles updated (7d)', '0'], ['Users added (7d)', '0'], ['Users removed (7d)', '0'], ['Failed access attempts (7d)', '0']]} /></SideCard>
          <SideCard title="Sensitive Actions (7d)"><KeyValueList rows={data.sensitiveRows || [['Role changes', '0'], ['Permission changes', '0'], ['User deactivations', '0'], ['Exported data', '0'], ['Security policy changes', '0']]} /></SideCard>
          <QuickActionList actions={['Create New Role', 'Manage Permission Groups', 'View Permission Matrix', 'Security Settings']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

const websiteColorFields = [
  ['bg', 'Background'],
  ['panel', 'Panel'],
  ['panel-2', 'Panel hover'],
  ['panel-3', 'Panel active'],
  ['text', 'Text'],
  ['muted', 'Muted text'],
  ['soft', 'Soft text'],
  ['line', 'Border'],
  ['line-strong', 'Strong border'],
  ['primary', 'Primary'],
  ['primary-2', 'Primary gradient'],
  ['primary-soft', 'Primary soft'],
  ['success', 'Success'],
  ['danger', 'Danger'],
  ['warning', 'Warning'],
];
const adminWebsiteColorFields = [
  ['bg', 'Admin background'],
  ['panel', 'Admin panel'],
  ['panel-2', 'Admin hover'],
  ['panel-3', 'Admin active'],
  ['text', 'Admin text'],
  ['heading', 'Admin heading'],
  ['muted', 'Admin muted'],
  ['soft', 'Admin soft'],
  ['border', 'Admin border'],
  ['border-strong', 'Admin strong border'],
  ['primary', 'Admin primary'],
  ['primary-2', 'Admin primary gradient'],
  ['primary-soft', 'Admin primary soft'],
];
const websiteFontSizeFields = [
  ['base', 'Base font size'],
  ['button', 'Button font size'],
  ['nav', 'Navigation font size'],
  ['sectionTitle', 'Section title size'],
  ['logo', 'Logo text size'],
  ['adminBase', 'Admin base font size'],
  ['adminTitle', 'Admin page title size'],
];
const websiteFontWeightFields = [
  ['normal', 'Normal weight'],
  ['medium', 'Medium weight'],
  ['bold', 'Bold weight'],
  ['heavy', 'Heavy weight'],
];
const websiteRadiiFields = [
  ['radiusMedia', 'Media radius'],
  ['radius', 'Default radius'],
  ['radiusLg', 'Large radius'],
  ['radiusPill', 'Pill radius'],
];
const websiteLayoutFields = [
  ['sidebarExpanded', 'Sidebar width'],
  ['sidebarCollapsed', 'Collapsed sidebar width'],
  ['topbar', 'Topbar height'],
  ['adminSidebarWidth', 'Admin sidebar width'],
];

function WebsiteConfigurationPage({ data = {}, api = null, onRefresh = null, alertAction = null, confirmAction = null }) {
  const [form, setForm] = useState(data.general || {});
  const [savedField, setSavedField] = useState('');
  const [saving, setSaving] = useState(false);
  const [uploadingAsset, setUploadingAsset] = useState('');
  const savedTimerRef = useRef(null);

  useEffect(() => {
    setForm(data.general || {});
  }, [data.general]);

  useEffect(() => () => {
    window.clearTimeout(savedTimerRef.current);
  }, []);

  const markSaved = (fieldId) => {
    window.clearTimeout(savedTimerRef.current);
    setSavedField(fieldId);
    savedTimerRef.current = window.setTimeout(() => setSavedField(''), 1600);
  };
  const applyLiveWebsite = (general) => {
    window.__PLAYHUB_APPLY_WEBSITE__?.(general, document.documentElement.dataset.theme || 'light');
  };
  const savePayload = async (payload, fieldId) => {
    if (!api?.request) return false;
    try {
      setSaving(true);
      const response = await api.request('/admin/general-settings', { method: 'PUT', body: JSON.stringify(payload) });
      markSaved(fieldId);
      if (response?.general) applyLiveWebsite(response.general);
      return true;
    } catch (error) {
      alertAction?.({ title: 'Website configuration not saved', message: error.message || 'Unable to save website configuration.', tone: 'danger' });
      return false;
    } finally {
      setSaving(false);
    }
  };
  const setWebsiteValue = (key, value) => setForm((current) => ({ ...current, [key]: value }));
  const setWebsiteValues = (values) => setForm((current) => ({ ...current, ...values }));
  const setAuthPreviewValue = (key, value) => setForm((current) => {
    const authPreview = { ...(current.authPreview || {}), [key]: value };
    return { ...current, authPreview };
  });
  const setAuthPreviewFeatures = (value) => {
    const lines = value.split('\n').slice(0, 4);
    while (lines.length < 4) lines.push('');
    const features = Object.fromEntries(lines.map((line, index) => [`feature${index + 1}`, line]));
    setForm((current) => ({ ...current, authPreview: { ...(current.authPreview || {}), ...features } }));
  };
  const setDesignValue = (section, key, value) => setForm((current) => {
    const design = current.design || {};
    const nextSection = { ...(design[section] || {}), [key]: value };
    return { ...current, design: { ...design, [section]: nextSection } };
  });
  const saveAll = async () => {
    if (await savePayload(form, 'website.save') && api?.request) {
      onRefresh?.();
    }
  };

  const resetToDefaults = async () => {
    if (!api?.request) return;
    if (!confirmAction) return;
    const confirmed = await confirmAction({
      title: 'Reset website configuration',
      message: 'Reset website configuration to defaults? This cannot be undone.',
      confirmLabel: 'Reset defaults',
      tone: 'danger',
    });
    if (!confirmed) return;
    try {
      setSaving(true);
      const response = await api.request('/admin/general-settings', { method: 'PUT', body: JSON.stringify({ resetDefaults: true }) });
      if (response?.general) {
        setForm(response.general);
        applyLiveWebsite(response.general);
      }
      markSaved('website.reset');
      onRefresh?.();
    } catch (error) {
      alertAction?.({ title: 'Reset failed', message: error.message || 'Unable to reset website configuration.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };
  const uploadAsset = async (asset, file) => {
    if (!api?.request || !file) return;
    if (asset === 'logo' && !/\.svg$/i.test(file.name || '')) {
      alertAction?.({ title: 'Upload failed', message: 'The logo must be an SVG file.', tone: 'danger' });
      return;
    }
    const payload = new FormData();
    payload.append('asset', asset);
    payload.append('file', file);
    try {
      setUploadingAsset(asset);
      const response = await api.request('/admin/website-assets', { method: 'POST', body: payload });
      if (response?.general) {
        setForm(response.general);
        applyLiveWebsite(response.general);
      }
      markSaved(asset === 'favicon' ? 'faviconUrl' : 'logoUrl');
      onRefresh?.();
    } catch (error) {
      alertAction?.({ title: 'Upload failed', message: error.message || 'Unable to upload website asset.', tone: 'danger' });
    } finally {
      setUploadingAsset('');
    }
  };

  const design = form.design || {};
  const fontOptions = Array.isArray(form.fontFamilyOptions) ? [...form.fontFamilyOptions] : [];
  if (form.fontFamily && !fontOptions.some(([value]) => value === form.fontFamily)) {
    fontOptions.unshift([form.fontFamily, 'Current custom font']);
  }
  const logoUrl = form.logoUrl || '';
  const faviconUrl = form.faviconUrl || '';
  const authPreview = form.authPreview || {};
  const authPreviewFeatures = [authPreview.feature1, authPreview.feature2, authPreview.feature3, authPreview.feature4].map((value) => value || '').join('\n');
  const defaultThemeMode = ['light', 'dark', 'system'].includes(form.defaultThemeMode) ? form.defaultThemeMode : 'system';
  const loadingDisplay = form.loadingDisplay === 'bar' ? 'bar' : 'instant';
  const defaultAccentKey = adminAccentPalettes.find(([, palette]) => String(form.defaultPrimaryColor || '').trim().toLowerCase() === palette.primary)?.[0] || '';
  const chooseDefaultAccent = (key) => {
    const palette = adminAccentPalettes.find(([accentKey]) => accentKey === key)?.[1];
    if (!palette) return;
    setWebsiteValues({
      defaultPrimaryColor: palette.primary,
      defaultSecondaryColor: palette.primary2,
      defaultPrimarySoftColor: palette.softLight,
    });
  };

  return (
    <div className="admin-content-grid settings-grid website-configuration-page">
      <div className="admin-settings-stack">
        <SettingsRow title="Branding" description="Control the public website name, browser title, logo image, favicon, and visible logo text.">
          <EditableField label="Website name" value={form.platformName || ''} onChange={(value) => setWebsiteValue('platformName', value)} saved={savedField === 'platformName'} />
          <EditableField label="Website title" value={form.websiteTitle || ''} onChange={(value) => setWebsiteValue('websiteTitle', value)} saved={savedField === 'websiteTitle'} />
          <EditableField label="Logo text" value={form.logoText || ''} onChange={(value) => setWebsiteValue('logoText', value)} saved={savedField === 'logoText'} />
          <EditableToggleField label="Show logo text" checked={form.showLogoText !== false} onChange={(value) => setWebsiteValue('showLogoText', value)} saved={savedField === 'showLogoText'} />
          <label className="admin-field wide">
            <span>Logo image size: {form.logoSize ?? 40}px</span>
            <input type="range" min="16" max="64" step="1" value={form.logoSize ?? 40} onChange={(event) => setWebsiteValue('logoSize', Number(event.target.value))} aria-label="Logo image size" />
          </label>
          <EditableField label="Logo URL" value={logoUrl} onChange={(value) => setWebsiteValue('logoUrl', value)} saved={savedField === 'logoUrl'} wide />
          <label className="admin-field wide">
            <span>Logo upload</span>
            <div><input type="file" accept=".svg,image/svg+xml" onChange={(event) => uploadAsset('logo', event.target.files?.[0])} /></div>
          </label>
          {logoUrl && <div className="website-asset-preview"><img src={logoUrl} alt="" /><span>{logoUrl}</span></div>}
          <EditableField label="Favicon URL" value={faviconUrl} onChange={(value) => setWebsiteValue('faviconUrl', value)} saved={savedField === 'faviconUrl'} wide />
          <label className="admin-field wide">
            <span>Favicon upload</span>
            <div><input type="file" accept=".ico,.png,.jpg,.jpeg,.webp,.svg" onChange={(event) => uploadAsset('favicon', event.target.files?.[0])} /></div>
          </label>
          {faviconUrl && <div className="website-asset-preview compact"><img src={faviconUrl} alt="" /><span>{faviconUrl}</span></div>}
        </SettingsRow>

        <SettingsRow title="Login & Registration Preview" description="Edit the content shown on the black side of login, registration, and password recovery pages. Enter one feature per line, up to four features.">
          <EditableField label="Title" value={authPreview.title || ''} onChange={(value) => setAuthPreviewValue('title', value)} saved={savedField === 'authPreview.title'} wide />
          <EditableTextarea label="Description" value={authPreview.description || ''} onChange={(value) => setAuthPreviewValue('description', value)} saved={savedField === 'authPreview.description'} />
          <EditableTextarea label="Features" value={authPreviewFeatures} onChange={setAuthPreviewFeatures} saved={savedField === 'authPreview.features'} />
        </SettingsRow>

        <SettingsRow title="Default Theme" description="Choose the primary website color used for guests and users without a saved accent color.">
          <ThemeModeField value={defaultThemeMode} onChange={(value) => setWebsiteValue('defaultThemeMode', value)} saved={savedField === 'defaultThemeMode'} />
          <AccentPaletteField value={defaultAccentKey} onChange={chooseDefaultAccent} saved={savedField.startsWith('defaultAccent.')} />
          <EditableField label="Default primary color" value={form.defaultPrimaryColor || ''} onChange={(value) => setWebsiteValue('defaultPrimaryColor', value)} saved={savedField === 'defaultPrimaryColor'} showColorPicker />
          <EditableField label="Default secondary color" value={form.defaultSecondaryColor || ''} onChange={(value) => setWebsiteValue('defaultSecondaryColor', value)} saved={savedField === 'defaultSecondaryColor'} showColorPicker />
          <EditableField label="Default soft color" value={form.defaultPrimarySoftColor || ''} onChange={(value) => setWebsiteValue('defaultPrimarySoftColor', value)} saved={savedField === 'defaultPrimarySoftColor'} showColorPicker />
          <EditableToggleField label="Sidebar burger" checked={form.showSidebarToggle !== false} onChange={(value) => setWebsiteValue('showSidebarToggle', value)} saved={savedField === 'showSidebarToggle'} />
        </SettingsRow>

        <SettingsRow title="Loading Experience" description="Choose whether navigation waits for destination content behind a progress bar or opens immediately with native loading placeholders.">
          <EditableSelect label="Loading behavior" value={loadingDisplay} onChange={(value) => setWebsiteValue('loadingDisplay', value)} options={[['bar', 'Top progress bar'], ['instant', 'Loading instantly with placeholders']]} saved={savedField === 'loadingDisplay'} wide />
        </SettingsRow>

        <SettingsRow title="Typography" description="Set the global font family, optional imported font URL, font sizes, and font weights.">
          <EditableSelect label="Font family" value={form.fontFamily || ''} onChange={(value) => setWebsiteValue('fontFamily', value)} options={fontOptions} saved={savedField === 'fontFamily'} wide />
          <EditableField label="Manual font family" value={form.fontFamily || ''} onChange={(value) => setWebsiteValue('fontFamily', value)} saved={savedField === 'fontFamily'} wide />
          <EditableField label="Font import URL" value={form.fontImportUrl || ''} onChange={(value) => setWebsiteValue('fontImportUrl', value)} saved={savedField === 'fontImportUrl'} wide />
          <div className="admin-settings-delimiter"><span>Font sizes</span></div>
          {websiteFontSizeFields.map(([key, label]) => <EditableField key={key} label={label} value={design.fontSizes?.[key] || ''} onChange={(value) => setDesignValue('fontSizes', key, value)} saved={savedField === `design.fontSizes.${key}`} />)}
          <div className="admin-settings-delimiter"><span>Font weights</span></div>
          {websiteFontWeightFields.map(([key, label]) => <EditableField key={key} label={label} value={design.fontWeights?.[key] || ''} onChange={(value) => setDesignValue('fontWeights', key, value)} saved={savedField === `design.fontWeights.${key}`} />)}
        </SettingsRow>

        <SettingsRow title="Website Colors" description="Manage the root CSS colors for light and dark website themes, including hover and active panel colors.">
          <div className="admin-settings-delimiter"><span>Light colors</span></div>
          {websiteColorFields.map(([key, label]) => {
              const value = design.colors?.[key] || '';
            return <EditableField key={key} label={label} value={value} onChange={(value) => setDesignValue('colors', key, value)} saved={savedField === `design.colors.${key}`} showColorPicker />;
          })}
          <div className="admin-settings-delimiter"><span>Dark colors</span></div>
          {websiteColorFields.map(([key, label]) => {
            const value = design.darkColors?.[key] || '';
            return <EditableField key={`dark-${key}`} label={label} value={value} onChange={(value) => setDesignValue('darkColors', key, value)} saved={savedField === `design.darkColors.${key}`} showColorPicker />;
          })}
        </SettingsRow>

        <SettingsRow title="Admin Panel Colors" description="Manage the admin panel CSS variables separately so the dashboard follows the selected design system.">
          <div className="admin-settings-delimiter"><span>Light admin colors</span></div>
          {adminWebsiteColorFields.map(([key, label]) => {
            const value = design.adminColors?.[key] || '';
            return <EditableField key={key} label={label} value={value} onChange={(value) => setDesignValue('adminColors', key, value)} saved={savedField === `design.adminColors.${key}`} showColorPicker />;
          })}
          <div className="admin-settings-delimiter"><span>Dark admin colors</span></div>
          {adminWebsiteColorFields.map(([key, label]) => {
            const value = design.adminDarkColors?.[key] || '';
            return <EditableField key={`admin-dark-${key}`} label={label} value={value} onChange={(value) => setDesignValue('adminDarkColors', key, value)} saved={savedField === `design.adminDarkColors.${key}`} showColorPicker />;
          })}
        </SettingsRow>

        <SettingsRow title="Shape & Layout" description="Manage global border radius values used by cards, panels, buttons, and larger surfaces.">
          {websiteRadiiFields.map(([key, label]) => <EditableField key={key} label={label} value={design.radii?.[key] || ''} onChange={(value) => setDesignValue('radii', key, value)} saved={savedField === `design.radii.${key}`} />)}
          <div className="admin-settings-delimiter"><span>Layout sizes</span></div>
          {websiteLayoutFields.map(([key, label]) => <EditableField key={key} label={label} value={design.layout?.[key] || ''} onChange={(value) => setDesignValue('layout', key, value)} saved={savedField === `design.layout.${key}`} />)}
          <button type="button" className="admin-outline-action" onClick={resetToDefaults} disabled={saving || Boolean(uploadingAsset)}>Reset to defaults</button>
          <button type="button" className="admin-outline-action primary-text" onClick={saveAll} disabled={saving || Boolean(uploadingAsset)}><AdminIcon name="settings" size={16} />{saving ? 'Saving...' : uploadingAsset ? 'Uploading...' : 'Save Website Configuration'}</button>
        </SettingsRow>
      </div>
    </div>
  );
}

function SeoPage({ data = {}, api = null, onRefresh = null, alertAction = null, confirmAction = null }) {
  const [form, setForm] = useState(data.settings || {});
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => setForm(data.settings || {}), [data.settings]);

  const updateGroup = (group, key, value) => setForm((current) => ({
    ...current,
    [group]: { ...(current[group] || {}), [key]: value },
  }));
  const updateSection = (group, bucket, section, value) => setForm((current) => ({
    ...current,
    [group]: {
      ...(current[group] || {}),
      [bucket]: { ...(current[group]?.[bucket] || {}), [section]: value },
    },
  }));
  const addMetaTag = () => setForm((current) => ({
    ...current,
    customMetaTags: [...(Array.isArray(current.customMetaTags) ? current.customMetaTags : []), { attribute: 'name', key: '', content: '' }],
  }));
  const updateMetaTag = (index, key, value) => setForm((current) => ({
    ...current,
    customMetaTags: (Array.isArray(current.customMetaTags) ? current.customMetaTags : []).map((tag, tagIndex) => tagIndex === index ? { ...tag, [key]: value } : tag),
  }));
  const removeMetaTag = (index) => setForm((current) => ({
    ...current,
    customMetaTags: (Array.isArray(current.customMetaTags) ? current.customMetaTags : []).filter((_, tagIndex) => tagIndex !== index),
  }));
  const save = async () => {
    if (!api?.request) return;
    try {
      setSaving(true);
      setSaved(false);
      const response = await api.request('/admin/seo-settings', { method: 'PUT', body: JSON.stringify(form) });
      if (response?.seo?.settings) setForm(response.seo.settings);
      setSaved(true);
      window.setTimeout(() => setSaved(false), 1800);
      onRefresh?.();
    } catch (error) {
      alertAction?.({ title: 'SEO settings not saved', message: error.message || 'Unable to save SEO settings.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };
  const reset = async () => {
    if (!api?.request || !confirmAction) return;
    const confirmed = await confirmAction({
      title: 'Reset SEO settings',
      message: 'Reset metadata, indexing, sitemap, robots, and verification settings to defaults?',
      confirmLabel: 'Reset defaults',
      tone: 'danger',
    });
    if (!confirmed) return;
    try {
      setSaving(true);
      const response = await api.request('/admin/seo-settings', { method: 'PUT', body: JSON.stringify({ resetDefaults: true }) });
      if (response?.seo?.settings) setForm(response.seo.settings);
      onRefresh?.();
    } catch (error) {
      alertAction?.({ title: 'Reset failed', message: error.message || 'Unable to reset SEO settings.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };
  const openPublicFile = (url) => {
    if (url) window.open(url, '_blank', 'noopener,noreferrer');
  };

  const summary = data.summary || {};
  const sections = data.sections || [];
  const metrics = [
    { label: 'Eligible URLs', value: Number(summary.eligible_urls || 0).toLocaleString(), change: 'Guest-accessible', sub: 'current records', icon: 'search', tone: 'blue', points: [] },
    { label: 'XML Sitemap Files', value: Number(summary.sitemap_files || 0).toLocaleString(), change: summary.sitemap_enabled ? 'Available' : 'Disabled', sub: 'generated on request', icon: 'content', tone: 'violet', points: [] },
    { label: 'Search Indexing', value: summary.indexing_enabled ? 'Enabled' : 'Blocked', change: summary.indexing_enabled ? 'Public pages' : 'Site-wide noindex', icon: 'globe', tone: summary.indexing_enabled ? 'green' : 'red', points: [] },
    { label: 'Sitemap Status', value: summary.sitemap_enabled ? 'Active' : 'Disabled', change: summary.sitemap_enabled ? 'Database-backed' : 'Not published', icon: 'activity', tone: summary.sitemap_enabled ? 'green' : 'orange', points: [] },
  ];
  const siteName = data.site?.name || configuredWebsiteName();
  const previewTitle = String(form.siteTitle || data.site?.title || siteName);
  const previewDescription = String(form.siteDescription || `Watch videos, Shorts, live streams, and creators on ${siteName}.`);
  const previewUrl = String(form.canonicalBaseUrl || data.site?.base_url || window.location.origin);
  const frequencyOptions = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'].map((value) => [value, value.replace(/^./, (letter) => letter.toUpperCase())]);

  return (
    <div className="seo-admin-page">
      <MetricGrid metrics={metrics} columns={4} />
      <div className="seo-public-files" aria-label="Public SEO files">
        <span><strong>Sitemap index</strong><small>{data.urls?.sitemap || '/sitemap.xml'}</small></span>
        <button type="button" className="admin-outline-action" onClick={() => openPublicFile(data.urls?.sitemap)} disabled={!summary.sitemap_enabled}><AdminIcon name="external" size={16} />Open</button>
        <span><strong>Robots policy</strong><small>{data.urls?.robots || '/robots.txt'}</small></span>
        <button type="button" className="admin-outline-action" onClick={() => openPublicFile(data.urls?.robots)}><AdminIcon name="external" size={16} />Open</button>
      </div>

      <div className="admin-content-grid settings-grid seo-settings-page">
        <div className="admin-settings-stack">
          <SettingsRow title="Search Appearance" description="Set the default title, description, keywords, title pattern, and social sharing image used across public pages.">
            <EditableField label="Site title" value={form.siteTitle || ''} onChange={(value) => setForm((current) => ({ ...current, siteTitle: value }))} placeholder={data.site?.title || siteName} wide />
            <EditableTextarea label="Site description" value={form.siteDescription || ''} onChange={(value) => setForm((current) => ({ ...current, siteDescription: value }))} placeholder={previewDescription} suffix={`${String(form.siteDescription || '').length}/320`} />
            <EditableTextarea label="Keywords" value={form.keywords || ''} onChange={(value) => setForm((current) => ({ ...current, keywords: value }))} placeholder="video, creators, live streaming" />
            <EditableField label="Title separator" value={form.titleSeparator || '|'} onChange={(value) => setForm((current) => ({ ...current, titleSeparator: value }))} />
            <EditableToggleField label="Append website name" checked={form.appendSiteName !== false} onChange={(value) => setForm((current) => ({ ...current, appendSiteName: value }))} />
            <EditableField label="Default social image URL" value={form.defaultImageUrl || ''} onChange={(value) => setForm((current) => ({ ...current, defaultImageUrl: value }))} placeholder="/uploads/website/social-cover.jpg" wide />
            <div className="seo-search-preview wide">
              <small>{previewUrl}</small>
              <h3>{previewTitle}</h3>
              <p>{previewDescription}</p>
            </div>
          </SettingsRow>

          <SettingsRow title="Canonical URLs & Verification" description="Set the public origin used in canonical URLs and sitemaps, then add ownership tokens supplied by search platforms.">
            <EditableField label="Canonical website URL" value={form.canonicalBaseUrl || ''} onChange={(value) => setForm((current) => ({ ...current, canonicalBaseUrl: value }))} placeholder={data.site?.base_url || window.location.origin} wide />
            <EditableField label="X account" value={form.twitterSite || ''} onChange={(value) => setForm((current) => ({ ...current, twitterSite: value }))} placeholder="@website" />
            <EditableField label="Google verification token" value={form.googleSiteVerification || ''} onChange={(value) => setForm((current) => ({ ...current, googleSiteVerification: value }))} wide />
            <EditableField label="Bing verification token" value={form.bingSiteVerification || ''} onChange={(value) => setForm((current) => ({ ...current, bingSiteVerification: value }))} wide />
          </SettingsRow>

          <SettingsRow title="Custom Meta Tags" description="Add global name or property metadata. PlayHub-managed search and social tags remain controlled by the fields above.">
            <div className="seo-custom-meta-list wide">
              {(form.customMetaTags || []).map((tag, index) => <div className="seo-custom-meta-row" key={`custom-meta-${index}`}>
                <label><span>Attribute</span><AdaptiveSelect value={tag.attribute || 'name'} onChange={(event) => updateMetaTag(index, 'attribute', event.target.value)}><option value="name">Name</option><option value="property">Property</option></AdaptiveSelect></label>
                <label><span>Key</span><input value={tag.key || ''} maxLength={128} placeholder={tag.attribute === 'property' ? 'og:custom' : 'author'} onChange={(event) => updateMetaTag(index, 'key', event.target.value)} /></label>
                <label><span>Content</span><input value={tag.content || ''} maxLength={2048} placeholder="Meta tag content" onChange={(event) => updateMetaTag(index, 'content', event.target.value)} /></label>
                <button type="button" className="seo-meta-remove" onClick={() => removeMetaTag(index)} aria-label={`Remove meta tag ${index + 1}`} title="Remove meta tag"><AdminIcon name="trash" size={17} /></button>
              </div>)}
              {!(form.customMetaTags || []).length && <div className="seo-custom-meta-empty">No custom meta tags configured.</div>}
            </div>
            <button type="button" className="admin-outline-action" onClick={addMetaTag} disabled={(form.customMetaTags || []).length >= 50}><AdminIcon name="plus" size={16} />Add meta tag</button>
          </SettingsRow>

          <SettingsRow title="Search Indexing" description="Control which guest-accessible page types may be indexed. Restricted and unpublished records remain excluded regardless of these settings.">
            <EditableToggleField label="Website indexing" checked={form.indexing?.enabled !== false} onChange={(value) => updateGroup('indexing', 'enabled', value)} />
            <div className="seo-section-toggles wide">
              {sections.map((section) => <label key={`index-${section.key}`}>
                <input type="checkbox" checked={form.indexing?.include?.[section.key] !== false} onChange={(event) => updateSection('indexing', 'include', section.key, event.target.checked)} />
                <span><strong>{section.label}</strong><small>{Number(section.eligible_urls || 0).toLocaleString()} eligible URLs</small></span>
              </label>)}
            </div>
          </SettingsRow>

          <SettingsRow title="XML Sitemap" description="Publish database-backed sitemap files with independent crawl frequency and priority values for each content type.">
            <EditableToggleField label="Publish XML sitemap" checked={form.sitemap?.enabled !== false} onChange={(value) => updateGroup('sitemap', 'enabled', value)} />
            <EditableField label="URLs per sitemap file" type="number" value={form.sitemap?.urlsPerFile || 10000} onChange={(value) => updateGroup('sitemap', 'urlsPerFile', value)} step="100" />
            <div className="seo-sitemap-table wide">
              <div className="seo-sitemap-head"><span>Content</span><span>Include</span><span>Frequency</span><span>Priority</span><span>Files</span></div>
              {sections.map((section) => <div className="seo-sitemap-row" key={`sitemap-${section.key}`}>
                <span><strong>{section.label}</strong><small>{Number(section.eligible_urls || 0).toLocaleString()} URLs</small></span>
                <label className="seo-table-check"><input type="checkbox" checked={form.sitemap?.include?.[section.key] !== false} onChange={(event) => updateSection('sitemap', 'include', section.key, event.target.checked)} /><span>Include</span></label>
                <AdaptiveSelect value={form.sitemap?.changeFrequency?.[section.key] || 'weekly'} onChange={(event) => updateSection('sitemap', 'changeFrequency', section.key, event.target.value)}>{frequencyOptions.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</AdaptiveSelect>
                <input aria-label={`${section.label} priority`} type="number" min="0" max="1" step="0.1" value={form.sitemap?.priority?.[section.key] ?? 0.5} onChange={(event) => updateSection('sitemap', 'priority', section.key, event.target.value)} />
                <span className="seo-file-count">{Number(section.sitemap_files || 0).toLocaleString()}</span>
              </div>)}
            </div>
          </SettingsRow>

          <SettingsRow title="Robots.txt" description="Private application routes are blocked automatically. Add any additional absolute paths that crawlers must not request, one per line.">
            <EditableTextarea label="Additional disallowed paths" value={form.robots?.customDisallow || ''} onChange={(value) => updateGroup('robots', 'customDisallow', value)} placeholder={'/campaign-preview\n/internal-directory/'} code />
            <button type="button" className="admin-outline-action" onClick={reset} disabled={saving}>Reset to defaults</button>
            <button type="button" className={`admin-outline-action primary-text ${saved ? 'saved' : ''}`} onClick={save} disabled={saving}><AdminIcon name="settings" size={16} />{saving ? 'Saving...' : saved ? 'Saved' : 'Save SEO Settings'}</button>
          </SettingsRow>
        </div>
      </div>
    </div>
  );
}

function SettingsPage({ navigate, data = {}, api = null, onRefresh = null, alertAction = null, confirmAction = null, routeSearch = '', cronJobsAction = null }) {
  const general = data.general || {};
  const notifications = data.notifications || {};
  const storage = data.storage || {};
  const authSettings = data.authSettings || {};
  const features = data.features || {};
  const videoImport = data.videoImport || {};
  const playerAds = data.playerAds || {};
  const liveStreaming = data.liveStreaming || {};
  const integrationSettings = data.integrationSettings || {};
  const [generalForm, setGeneralForm] = useState(general || {});
  const [storageForm, setStorageForm] = useState(storage.config || {});
  const [storageConfigMembership, setStorageConfigMembership] = useState(storage.config?.defaultMembership || 'starter');
  const [authForm, setAuthForm] = useState(authSettings || {});
  const [featureForm, setFeatureForm] = useState(features || {});
  const [videoImportForm, setVideoImportForm] = useState(videoImport || {});
  const [playerAdForm, setPlayerAdForm] = useState(playerAds || {});
  const [liveStreamingForm, setLiveStreamingForm] = useState(liveStreaming || {});
  const [integrationForm, setIntegrationForm] = useState(integrationSettings || {});
  const [managedCurrencyCatalog, setManagedCurrencyCatalog] = useState(() => window.__PLAYHUB_WEBSITE__?.currencies || null);
  const [activeIntegration, setActiveIntegration] = useState('Google Analytics');
  const [generalSaving, setGeneralSaving] = useState(false);
  const [storageSaving, setStorageSaving] = useState(false);
  const [authSaving, setAuthSaving] = useState(false);
  const [featureSaving, setFeatureSaving] = useState(false);
  const [storageTesting, setStorageTesting] = useState(false);
  const [videoImportSaving, setVideoImportSaving] = useState(false);
  const [playerAdSaving, setPlayerAdSaving] = useState(false);
  const [liveStreamingSaving, setLiveStreamingSaving] = useState(false);
  const [liveKitAction, setLiveKitAction] = useState('');
  const [liveKitLogs, setLiveKitLogs] = useState(null);
  const [liveKitConnection, setLiveKitConnection] = useState(null);
  const [integrationSaving, setIntegrationSaving] = useState(false);
  const [smtpTesting, setSmtpTesting] = useState(false);
  const [smsOtpTesting, setSmsOtpTesting] = useState(false);
  const [sendGridTesting, setSendGridTesting] = useState(false);
  const [backupDialogOpen, setBackupDialogOpen] = useState(false);
  const [savedSettingField, setSavedSettingField] = useState('');
  const toast = useToast();
  const [publishingRestrictions, setPublishingRestrictions] = useState([]);
  const [publishingRestrictionsLoading, setPublishingRestrictionsLoading] = useState(false);
  const [publishingRestrictionBusy, setPublishingRestrictionBusy] = useState(false);
  const [publishingRestrictionForm, setPublishingRestrictionForm] = useState({ username: '', duration: '1_day', scopes: ['uploads'] });
  const settingsHighlightTimerRef = useRef(null);
  const email = data.email || {};
  useEffect(() => {
    const refreshCurrencyCatalog = () => setManagedCurrencyCatalog(window.__PLAYHUB_WEBSITE__?.currencies || null);
    window.addEventListener('playhub:currencies-ready', refreshCurrencyCatalog);
    window.addEventListener('playhub:website-config', refreshCurrencyCatalog);
    return () => {
      window.removeEventListener('playhub:currencies-ready', refreshCurrencyCatalog);
      window.removeEventListener('playhub:website-config', refreshCurrencyCatalog);
    };
  }, []);
  const moderation = data.moderation || {};
  const maintenance = data.maintenance || {};
  const integrations = data.integrations || [
    { icon: 'analytics', name: 'Google Analytics', status: 'Not configured' },
    { icon: 'stripe', name: 'Stripe', status: 'Not configured' },
    { icon: 'paypal', name: 'PayPal', status: 'Not configured' },
    { icon: 'sendgrid', name: 'SendGrid', status: 'Not configured' },
  ];
  useEffect(() => setGeneralForm(data.general || {}), [data.general]);
  useEffect(() => setStorageForm(storage.config || {}), [storage.config]);
  useEffect(() => setStorageConfigMembership((current) => storageMembershipOptions.some(([value]) => value === current) ? current : (storage.config?.defaultMembership || 'starter')), [storage.config]);
  useEffect(() => setAuthForm(authSettings || {}), [authSettings]);
  useEffect(() => setFeatureForm(features || {}), [features]);
  useEffect(() => setVideoImportForm(videoImport || {}), [videoImport]);
  useEffect(() => setPlayerAdForm(playerAds || {}), [playerAds]);
  useEffect(() => setLiveStreamingForm(liveStreaming || {}), [liveStreaming]);
  useEffect(() => setIntegrationForm(integrationSettings || {}), [integrationSettings]);
  useEffect(() => {
    if (adminRouteParam(routeSearch, 'open') === 'backup') setBackupDialogOpen(true);
  }, [routeSearch]);
  useEffect(() => {
    if (!api?.request) return undefined;
    let cancelled = false;
    const refresh = async () => {
      try {
        const response = await api.request('/admin/livekit-status');
        if (cancelled) return;
        setLiveStreamingForm((current) => ({
          ...current,
          livekit: {
            ...(current.livekit || {}),
            status: response.docker?.container?.status || current.livekit?.status || 'unknown',
            ready: Boolean(response.ready),
            docker: response.docker || {},
          },
        }));
      } catch (_) {}
    };
    const timer = window.setInterval(refresh, 10000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [api]);
  const storageProvider = storageForm.mediaStorage || 'default';
  const activeStorageProvider = storageProvider === 'default' ? 'local' : storageProvider;
  const storageProviderTabs = [
    { id: 'local', icon: 'storage', name: 'Local uploads', status: activeStorageProvider === 'local' ? 'Active' : 'Local', wide: true },
    { id: 's3', icon: 'cloud', name: 'S3 / compatible', status: activeStorageProvider === 's3' ? 'Active' : 'Cloud' },
    { id: 'azure', icon: 'cloud', name: 'Azure Blob', status: activeStorageProvider === 'azure' ? 'Active' : 'Cloud' },
    { id: 'gcs', icon: 'cloud', name: 'Google Cloud Storage', status: activeStorageProvider === 'gcs' ? 'Active' : 'Cloud' },
    { id: 'b2', icon: 'cloud', name: 'Backblaze B2', status: activeStorageProvider === 'b2' ? 'Active' : 'Cloud' },
  ];
  const providerConfig = storageForm[storageProvider] || {};
  const storageCapacity = storage.capacity || {};
  const storageTotalLabel = storageProvider === 'default' || storageProvider === 'local' ? (storageCapacity.total || 'Not configured') : `${storageForm.cloudStorageTotalGb || 0} GB`;
  const storageTotalSource = storageProvider === 'default' || storageProvider === 'local' ? (storageCapacity.source || '') : 'Configured cloud capacity';
  const membershipQuotas = storageForm.membershipQuotasGb || {};
  const membershipPrices = storageForm.membershipPricesUsd || {};
  const selectedMembership = storageConfigMembership || 'starter';
  const selectedMembershipLabel = storageMembershipOptions.find(([value]) => value === selectedMembership)?.[1] || 'Starter';
  const playerAdItems = Array.isArray(playerAdForm.ads) ? playerAdForm.ads : [];
  const liveKitForm = liveStreamingForm.livekit || {};
  const liveKitDocker = liveKitForm.docker || {};
  const liveKitContainer = liveKitDocker.container || {};
  const liveKitImage = liveKitDocker.image || {};
  const liveKitIngressContainer = liveKitDocker.ingress_container || {};
  const liveKitIngressImage = liveKitDocker.ingress_image || {};
  const liveKitEgressContainer = liveKitDocker.egress_container || {};
  const liveKitEgressImage = liveKitDocker.egress_image || {};
  const liveKitDockerAvailable = liveKitDocker.available === true;
  const liveKitRunning = Boolean(liveKitContainer.running ?? (liveKitForm.status === 'running'));
  const liveKitDockerStatusLabels = { running: 'Running', exited: 'Stopped', created: 'Created', restarting: 'Restarting', paused: 'Paused', dead: 'Failed', missing: 'Missing' };
  const liveKitStatusLabel = liveKitDockerStatusLabels[liveKitContainer.status || liveKitForm.status] || 'Unknown';
  const liveKitContainerId = String(liveKitContainer.id || '').slice(0, 12) || 'Not available';
  const liveKitImageId = String(liveKitImage.id || liveKitContainer.image_id || '').replace(/^sha256:/, '').slice(0, 12) || 'Not available';
  const liveKitImageSize = Number(liveKitImage.size || 0) > 0 ? `${(Number(liveKitImage.size) / 1024 / 1024).toFixed(1)} MB` : '';
  const liveKitIngressRunning = Boolean(liveKitIngressContainer.running);
  const liveKitIngressStatusLabel = liveKitDockerStatusLabels[liveKitIngressContainer.status] || 'Unknown';
  const liveKitIngressContainerId = String(liveKitIngressContainer.id || '').slice(0, 12) || 'Not available';
  const liveKitIngressImageId = String(liveKitIngressImage.id || liveKitIngressContainer.image_id || '').replace(/^sha256:/, '').slice(0, 12) || 'Not available';
  const liveKitIngressImageSize = Number(liveKitIngressImage.size || 0) > 0 ? `${(Number(liveKitIngressImage.size) / 1024 / 1024).toFixed(1)} MB` : '';
  const liveKitEgressRunning = Boolean(liveKitEgressContainer.running);
  const liveKitEgressStatusLabel = liveKitDockerStatusLabels[liveKitEgressContainer.status] || 'Unknown';
  const liveKitEgressContainerId = String(liveKitEgressContainer.id || '').slice(0, 12) || 'Not available';
  const liveKitEgressImageId = String(liveKitEgressImage.id || liveKitEgressContainer.image_id || '').replace(/^sha256:/, '').slice(0, 12) || 'Not available';
  const liveKitEgressImageSize = Number(liveKitEgressImage.size || 0) > 0 ? `${(Number(liveKitEgressImage.size) / 1024 / 1024).toFixed(1)} MB` : '';
  const liveKitDockerUpdated = liveKitDocker.updated_at ? new Date(liveKitDocker.updated_at).toLocaleString() : 'Not available';
  const googleAnalyticsForm = integrationForm.googleAnalytics || {};
  const stripeForm = integrationForm.stripe || {};
  const paypalForm = integrationForm.paypal || {};
  const smtpForm = integrationForm.smtp || {};
  const smsOtpForm = integrationForm.smsOtp || {};
  const smsOtpProvider = smsOtpForm.provider || 'twilio';
  const smsOtpProviderOptions = [['twilio', 'Twilio'], ['vonage', 'Vonage'], ['messagebird', 'MessageBird']];
  const sendGridForm = integrationForm.sendgrid || {};
  const googleAuthForm = authForm.social?.google || {};
  const facebookAuthForm = authForm.social?.facebook || {};
  const appleAuthForm = authForm.social?.apple || {};
  const paymentForm = integrationForm.payments || {};
  const managedCurrencyItems = Array.isArray(managedCurrencyCatalog?.items) ? managedCurrencyCatalog.items : [];
  const currencyManagerActive = managedCurrencyCatalog?.managed === true && Boolean(managedCurrencyCatalog?.default);
  const managedCurrencyPath = managedCurrencyCatalog?.admin_path || '/admin/extensions/kontackt.currency-manager';
  const paymentCurrencyValue = currencyManagerActive ? String(managedCurrencyCatalog.default).toLowerCase() : (paymentForm.currency || 'usd');
  const paymentCurrencyOptions = currencyManagerActive
    ? managedCurrencyItems.map((currency) => [String(currency.code).toLowerCase(), `${currency.code} - ${currency.name}`])
    : [['usd', 'USD'], ['eur', 'EUR'], ['gbp', 'GBP'], ['cad', 'CAD'], ['aud', 'AUD']];
  const paymentCurrency = String(paymentCurrencyValue).toUpperCase();
  const paymentCurrencyLabel = managedCurrencyItems.find((currency) => currency.code === paymentCurrency)?.symbol || { USD: '$', EUR: '€', GBP: '£' }[paymentCurrency] || paymentCurrency;
  const timezoneOptions = Array.isArray(general.timezoneOptions) && general.timezoneOptions.length ? general.timezoneOptions : [['UTC', 'UTC']];
  const activeSettingSection = adminRouteParam(routeSearch, 'section');
  const highlightedSettingId = useAdminRouteTarget(routeSearch, activeSettingSection);
  const selectedSettingsBlock = systemSettingsBlockFromRoute(routeSearch, highlightedSettingId);
  const settingsRowProps = (id) => ({
    id,
    visible: selectedSettingsBlock.id === id,
    highlight: highlightedSettingId === id,
  });
  const videoImportProviders = (videoImportForm.provider_definitions || []).map((provider) => [provider.key, provider.label, provider.api_key_label]);

  const notificationSettings = featureForm.notifications || notifications || {};
  const moderationSettings = featureForm.moderation || moderation || {};
  const maintenanceSettings = featureForm.maintenance || maintenance || {};
  const uploadSettings = featureForm.uploads || {};
  const publishingSettings = featureForm.publishing || {};
  const showSettingsToast = (message = 'Settings saved successfully.', tone = 'success') => toast[tone](message);
  useEffect(() => {
    if (selectedSettingsBlock.id !== 'setting-publishing-access' || !api?.request) return undefined;
    let cancelled = false;
    setPublishingRestrictionsLoading(true);
    api.request('/admin/publishing-restrictions')
      .then((response) => { if (!cancelled) setPublishingRestrictions(response.restrictions || []); })
      .catch((error) => { if (!cancelled) showSettingsToast(error.message || 'Unable to load publishing restrictions.', 'error'); })
      .finally(() => { if (!cancelled) setPublishingRestrictionsLoading(false); });
    return () => { cancelled = true; };
  }, [api, selectedSettingsBlock.id]);
  const highlightSavedField = (fieldId) => {
    if (!fieldId) return;
    window.clearTimeout(settingsHighlightTimerRef.current);
    setSavedSettingField(fieldId);
    settingsHighlightTimerRef.current = window.setTimeout(() => setSavedSettingField(''), 1600);
  };
  const saveSettingsPayload = async (kind, payload, fieldId = '') => {
    if (!api?.request) return;
    try {
      const path = kind === 'general' ? '/admin/general-settings' : kind === 'storage' ? '/admin/storage-settings' : kind === 'ads' ? '/admin/player-ad-settings' : kind === 'live' ? '/admin/live-streaming-settings' : kind === 'integration' ? '/admin/integration-settings' : kind === 'auth' ? '/admin/auth-settings' : kind === 'features' ? '/admin/feature-settings' : '/admin/video-import-settings';
      const response = await api.request(path, { method: 'PUT', body: JSON.stringify(payload) });
      if (kind === 'general' && response?.general) setGeneralForm(response.general);
      if (kind === 'storage' && response?.storage) setStorageForm(response.storage);
      if (kind === 'auth' && response?.authSettings) setAuthForm(response.authSettings);
      if (kind === 'features' && response?.features) setFeatureForm(response.features);
      if (kind === 'integration' && response?.integrationSettings) setIntegrationForm(response.integrationSettings);
      if (kind === 'live' && response?.liveStreaming) {
        setLiveStreamingForm((current) => fieldId === 'live.product.save'
          ? { ...response.liveStreaming, livekit: current.livekit }
          : { ...current, engine: response.liveStreaming.engine, livekit: response.liveStreaming.livekit });
      }
      highlightSavedField(fieldId);
      showSettingsToast(response?.message || 'Settings saved successfully.');
      return true;
    } catch (error) {
      showSettingsToast(error.message || 'Unable to save settings.', 'error');
      return false;
    }
  };
  const setGeneralValue = (key, value) => setGeneralForm((current) => ({ ...current, [key]: value }));
  const setStorageValue = (key, value) => setStorageForm((current) => ({ ...current, [key]: value }));
  const setAuthValue = (section, key, value) => setAuthForm((current) => {
    return { ...current, [section]: { ...(current[section] || {}), [key]: value } };
  });
  const setAuthProviderValue = (provider, key, value) => setAuthForm((current) => {
    return { ...current, social: { ...(current.social || {}), [provider]: { ...((current.social || {})[provider] || {}), [key]: value } } };
  });
  const setAuthCaptchaValue = (key, value) => setAuthForm((current) => {
    return { ...current, captcha: { ...(current.captcha || {}), [key]: value } };
  });
  const setAuthCaptchaProviderValue = (provider, key, value) => setAuthForm((current) => {
    const captcha = current.captcha || {};
    const providers = captcha.providers || {};
    return { ...current, captcha: { ...captcha, providers: { ...providers, [provider]: { ...(providers[provider] || {}), [key]: value } } } };
  });
  const setFeatureValue = (section, key, value) => setFeatureForm((current) => {
    return { ...current, [section]: { ...(current[section] || {}), [key]: value } };
  });
  const togglePublishingRole = (capability, role, checked) => setFeatureForm((current) => {
    const publishing = current.publishing || {};
    const key = capability === 'live' ? 'liveRoles' : 'uploadRoles';
    const roles = Array.isArray(publishing[key]) ? publishing[key] : ['creator', 'moderator', 'admin'];
    const nextRoles = checked ? Array.from(new Set([...roles, role])) : roles.filter((item) => item !== role);
    return { ...current, publishing: { ...publishing, [key]: nextRoles } };
  });
  const togglePublishingRestrictionScope = (scope, checked) => setPublishingRestrictionForm((current) => ({
    ...current,
    scopes: checked ? Array.from(new Set([...current.scopes, scope])) : current.scopes.filter((item) => item !== scope),
  }));
  const applyPublishingRestriction = async () => {
    if (!publishingRestrictionForm.username.trim() || !publishingRestrictionForm.scopes.length || !api?.request) return;
    setPublishingRestrictionBusy(true);
    try {
      const response = await api.request('/admin/publishing-restrictions', { method: 'POST', body: JSON.stringify(publishingRestrictionForm) });
      setPublishingRestrictions(response.restrictions || []);
      setPublishingRestrictionForm((current) => ({ ...current, username: '' }));
      showSettingsToast(response.message || 'Publishing restriction applied.');
    } catch (error) {
      showSettingsToast(error.message || 'Unable to restrict publishing.', 'error');
    } finally {
      setPublishingRestrictionBusy(false);
    }
  };
  const clearPublishingRestriction = async (restriction) => {
    const confirmed = confirmAction ? await confirmAction({ title: 'Clear publishing restriction?', message: `@${restriction.username} will regain access according to the current role and allowlist settings.`, confirmLabel: 'Clear restriction' }) : true;
    if (!confirmed || !api?.request) return;
    setPublishingRestrictionBusy(true);
    try {
      const response = await api.request(`/admin/publishing-restrictions/${restriction.user_id}`, { method: 'DELETE' });
      setPublishingRestrictions(response.restrictions || []);
      showSettingsToast(response.message || 'Publishing restriction cleared.');
    } catch (error) {
      showSettingsToast(error.message || 'Unable to clear publishing restriction.', 'error');
    } finally {
      setPublishingRestrictionBusy(false);
    }
  };
  const toggleUploadRendition = (quality, checked) => setFeatureForm((current) => {
    const uploads = current.uploads || {};
    const renditions = Array.isArray(uploads.renditions) ? uploads.renditions : ['original', 'high', 'medium', 'low'];
    if (!checked && renditions.length <= 1) return current;
    const nextRenditions = checked ? Array.from(new Set([...renditions, quality])) : renditions.filter((item) => item !== quality);
    return { ...current, uploads: { ...uploads, renditions: nextRenditions, defaultQuality: uploads.defaultQuality !== 'auto' && !nextRenditions.includes(uploads.defaultQuality) ? 'auto' : (uploads.defaultQuality || 'auto') } };
  });
  const setLiveStreamingValue = (section, key, value) => setLiveStreamingForm((current) => ({ ...current, [section]: { ...(current[section] || {}), [key]: value } }));
  const setLiveStreamingRootValue = (key, value) => setLiveStreamingForm((current) => ({ ...current, [key]: value }));
  const setLiveKitFileValue = (key, modeKey, value) => setLiveStreamingForm((current) => ({
    ...current,
    livekit: { ...(current.livekit || {}), [key]: value, ...(modeKey ? { [modeKey]: 'custom' } : {}) },
  }));
  const toggleLiveStreamingOption = (group, value, checked) => setLiveStreamingForm((current) => {
    const values = Array.isArray(current.options?.[group]) ? current.options[group] : [];
    if (!checked && values.length <= 1) return current;
    const nextValues = checked ? Array.from(new Set([...values, value])) : values.filter((item) => String(item) !== String(value));
    const defaultKeys = { resolutions: 'resolution', latency_modes: 'latency_mode', replay_retention: 'replay_retention', audience_modes: 'audience_mode', moderation_levels: 'moderation_level', slow_mode_intervals: 'slow_mode_interval', frame_rates: 'frame_rate' };
    const defaultKey = defaultKeys[group];
    const defaults = { ...(current.defaults || {}) };
    if (defaultKey && !nextValues.some((item) => String(item) === String(defaults[defaultKey]))) defaults[defaultKey] = nextValues[0];
    return { ...current, defaults, options: { ...(current.options || {}), [group]: nextValues } };
  });
  const setStorageMembershipQuota = (membership, value) => setStorageForm((current) => ({ ...current, membershipQuotasGb: { ...(current.membershipQuotasGb || {}), [membership]: value } }));
  const setStorageMembershipPrice = (membership, value) => setStorageForm((current) => ({ ...current, membershipPricesUsd: { ...(current.membershipPricesUsd || {}), [membership]: value } }));
  const setProviderValue = (key, value) => setStorageForm((current) => ({
    ...current,
    [storageProvider]: { ...(current[storageProvider] || {}), [key]: value },
    clearSecrets: (current.clearSecrets || []).filter((secret) => secret !== `${storageProvider}.${key}`),
  }));
  const toggleClearStorageSecret = (key) => setStorageForm((current) => {
    const secret = `${storageProvider}.${key}`;
    const clearSecrets = current.clearSecrets || [];
    return {
      ...current,
      [storageProvider]: { ...(current[storageProvider] || {}), [key]: '' },
      clearSecrets: clearSecrets.includes(secret) ? clearSecrets.filter((item) => item !== secret) : [...clearSecrets, secret],
    };
  });
  const storageSecretField = (label, key, wide = false) => <StorageSecretField
    key={key} label={label} value={providerConfig[key] || ''} wide={wide}
    configured={Boolean(storageForm.configuredSecrets?.[`${storageProvider}.${key}`])}
    clear={(storageForm.clearSecrets || []).includes(`${storageProvider}.${key}`)}
    onChange={(value) => setProviderValue(key, value)} onClear={() => toggleClearStorageSecret(key)} />;
  const setVideoImportValue = (key, value) => setVideoImportForm((current) => ({ ...current, [key]: value }));
  const setVideoImportProviderValue = (provider, key, value) => setVideoImportForm((current) => {
    return { ...current, providers: { ...(current.providers || {}), [provider]: { ...((current.providers || {})[provider] || {}), [key]: value } } };
  });
  const setPlayerAdValue = (key, value) => setPlayerAdForm((current) => ({ ...current, [key]: value }));
  const setPlayerAdItemValue = (index, key, value) => setPlayerAdForm((current) => {
    const ads = Array.isArray(current.ads) ? current.ads : [];
    const nextAds = ads.map((ad, adIndex) => adIndex === index ? { ...ad, [key]: value } : ad);
    return { ...current, ads: nextAds };
  });
  const setIntegrationValue = (provider, key, value) => setIntegrationForm((current) => {
    return { ...current, [provider]: { ...((current || {})[provider] || {}), [key]: value } };
  });
  const setPaymentValue = (key, value) => setIntegrationForm((current) => {
    return { ...current, payments: { ...((current || {}).payments || {}), [key]: value } };
  });
  const addPlayerAdItem = () => setPlayerAdForm((current) => {
    const ads = Array.isArray(current.ads) ? current.ads : [];
    return { ...current, ads: [...ads, { id: `ad-${Date.now()}`, enabled: true, name: `Player Ad ${ads.length + 1}`, engine: 'generic_vast', placement: 'preroll', vastUrl: '', skipAfter: 5, offsetSeconds: 30, grossCpm: 0 }] };
  });
  const removePlayerAdItem = (index) => setPlayerAdForm((current) => {
    const ads = Array.isArray(current.ads) ? current.ads : [];
    return { ...current, ads: ads.filter((_, adIndex) => adIndex !== index) };
  });
  useEffect(() => () => {
    window.clearTimeout(settingsHighlightTimerRef.current);
  }, []);
  const saveGeneralSettings = async () => {
    if (!api?.request) return;
    setGeneralSaving(true);
    try {
      const generalResponse = await api.request('/admin/general-settings', {
        method: 'PUT',
        body: JSON.stringify({ timezone: generalForm.timezone || 'UTC' }),
      });
      const integrationResponse = currencyManagerActive ? null : await api.request('/admin/integration-settings', {
        method: 'PUT',
        body: JSON.stringify({ payments: { currency: paymentForm.currency || 'usd' } }),
      });
      if (generalResponse?.general) setGeneralForm(generalResponse.general);
      if (integrationResponse?.integrationSettings?.payments) {
        setIntegrationForm((current) => ({ ...current, payments: integrationResponse.integrationSettings.payments }));
      }
      highlightSavedField('general.save');
      showSettingsToast('General settings saved successfully.');
    } catch (error) {
      showSettingsToast(error.message || 'Unable to save general settings.', 'error');
    } finally {
      setGeneralSaving(false);
    }
  };
  const saveStorageSettings = async () => {
    if (!api?.request) return;
    setStorageSaving(true);
    await saveSettingsPayload('storage', storageForm, 'storage.save');
    setStorageSaving(false);
  };
  const saveAuthSettings = async () => {
    if (!api?.request) return;
    setAuthSaving(true);
    await saveSettingsPayload('auth', authForm, 'auth.save');
    setAuthSaving(false);
  };
  const saveFeatureSettings = async () => {
    if (!api?.request) return;
    setFeatureSaving(true);
    await saveSettingsPayload('features', featureForm, 'features.save');
    setFeatureSaving(false);
  };
  const testStorageSettings = async () => {
    if (!api?.request) return;
    setStorageTesting(true);
    await adminJsonAction(api, '/admin/storage-settings/test', { method: 'POST', body: JSON.stringify(storageForm) }, null, alertAction);
    setStorageTesting(false);
  };
  const saveVideoImportSettings = async () => {
    if (!api?.request) return;
    setVideoImportSaving(true);
    await saveSettingsPayload('import', videoImportForm, 'import.save');
    setVideoImportSaving(false);
  };
  const savePlayerAdSettings = async () => {
    if (!api?.request) return;
    setPlayerAdSaving(true);
    await saveSettingsPayload('ads', playerAdForm, 'ads.save');
    setPlayerAdSaving(false);
  };
  const saveLiveStreamingSettings = async (scope = 'product') => {
    if (!api?.request) return;
    setLiveStreamingSaving(true);
    const monetization = liveStreamingForm.monetization || {};
    const productPayload = {
      enabled: liveStreamingForm.enabled,
      features: liveStreamingForm.features,
      options: liveStreamingForm.options,
      defaults: liveStreamingForm.defaults,
      schedule: liveStreamingForm.schedule,
      limits: liveStreamingForm.limits,
      monetization: {
        ...monetization,
        tip_min_cents: Math.round(Number(monetization.tip_min || 0) * 100),
        tip_max_cents: Math.round(Number(monetization.tip_max || 0) * 100),
        ad_gross_cents: Math.round(Number(monetization.ad_gross || 0) * 100),
      },
    };
    const payload = scope === 'livekit' ? { livekit: liveStreamingForm.livekit || {} } : productPayload;
    await saveSettingsPayload('live', payload, scope === 'livekit' ? 'live.livekit.save' : 'live.product.save');
    setLiveStreamingSaving(false);
  };
  const runLiveKitAction = async (action) => {
    if (!api?.request || liveKitAction) return;
    setLiveKitAction(action);
    try {
      const response = await api.request('/admin/livekit-action', { method: 'POST', body: JSON.stringify({ action }) });
      showSettingsToast(response?.message || 'LiveKit Docker action requested.');
    } catch (error) {
      showSettingsToast(error.message || 'Unable to run the LiveKit Docker action.', 'error');
    } finally {
      setLiveKitAction('');
    }
  };
  const loadLiveKitLogs = async () => {
    if (!api?.request || liveKitAction) return;
    setLiveKitAction('logs');
    try {
      const response = await api.request('/admin/livekit-logs');
      const logs = response?.logs || {};
      setLiveKitLogs(formatLiveKitLogs(logs));
    } catch (error) {
      showSettingsToast(error.message || 'Unable to load LiveKit logs.', 'error');
    } finally {
      setLiveKitAction('');
    }
  };
  const testLiveKitConnection = async () => {
    if (!api?.request || liveKitAction) return;
    setLiveKitAction('test');
    try {
      const response = await api.request('/admin/livekit-test', { method: 'POST' });
      const connection = response?.connection || { success: false, message: 'LiveKit did not return a connection result.' };
      setLiveKitConnection(connection);
      showSettingsToast(connection.message || (connection.success ? 'LiveKit connection succeeded.' : 'LiveKit connection failed.'), connection.success ? 'success' : 'error');
    } catch (error) {
      const connection = { success: false, message: error.message || 'Unable to test the LiveKit connection.' };
      setLiveKitConnection(connection);
      showSettingsToast(connection.message, 'error');
    } finally {
      setLiveKitAction('');
    }
  };
  const saveIntegrationSettings = async () => {
    if (!api?.request) return;
    setIntegrationSaving(true);
    await saveSettingsPayload('integration', integrationForm, 'integration.save');
    setIntegrationSaving(false);
  };
  const testSmtpSettings = async () => {
    if (!api?.request) return;
    setSmtpTesting(true);
    await adminJsonAction(api, '/admin/integration-settings/test', { method: 'POST', body: JSON.stringify({ provider: 'smtp' }) }, null, alertAction);
    setSmtpTesting(false);
  };
  const testSmsOtpSettings = async () => {
    if (!api?.request) return;
    setSmsOtpTesting(true);
    await adminJsonAction(api, '/admin/integration-settings/test', { method: 'POST', body: JSON.stringify({ provider: 'smsOtp', phone: smsOtpForm.testPhone || '' }) }, null, alertAction);
    setSmsOtpTesting(false);
  };
  const testSendGridSettings = async () => {
    if (!api?.request) return;
    setSendGridTesting(true);
    await adminJsonAction(api, '/admin/integration-settings/test', { method: 'POST', body: JSON.stringify({ provider: 'sendgrid' }) }, null, alertAction);
    setSendGridTesting(false);
  };
  const runQuickAction = (action) => {
    if (action === 'Run Backup Now') {
      setBackupDialogOpen(true);
      return;
    }
    if (action === 'Manage Cron Jobs') {
      cronJobsAction?.();
      return;
    }
    return runAdminQuickAction(action, { navigate, api, data, alertAction });
  };

  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid settings-grid">
        <div className="admin-settings-stack">
          <SettingsRow {...settingsRowProps('setting-general')} title="General" description="Configure basic platform settings and default behavior.">
            <EditableSelect label="Default Timezone" value={generalForm.timezone || 'UTC'} onChange={(value) => setGeneralValue('timezone', value)} options={timezoneOptions} saved={savedSettingField === 'general.timezone'} wide />
            <SelectField label="Items Per Page" value={general.itemsPerPage || '25'} />
            <EditableSelect label={currencyManagerActive ? 'Payment Currency (managed by plugin)' : 'Payment Currency'} value={paymentCurrencyValue} onChange={(value) => setPaymentValue('currency', value)} options={paymentCurrencyOptions} saved={savedSettingField === 'integration.payments.currency'} disabled={currencyManagerActive} />
            {currencyManagerActive && <button type="button" className="admin-outline-action" onClick={() => navigate(managedCurrencyPath)}><AdminIcon name="monetization" size={16} />Manage currencies</button>}
            <button type="button" className="admin-outline-action primary-text" onClick={saveGeneralSettings} disabled={generalSaving}><AdminIcon name="settings" size={16} />{generalSaving ? 'Saving...' : 'Save General Settings'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-registration')} title="Registration" description="Control public account creation and block registration from specific IPs or IP prefixes.">
            <EditableToggleField label="Public Registration" checked={authForm.registration?.enabled ?? true} onChange={(value) => setAuthValue('registration', 'enabled', value)} saved={savedSettingField === 'auth.registration.enabled'} />
            <EditableSelect label="Default signup role" value={authForm.registration?.defaultRole || 'user'} onChange={(value) => setAuthValue('registration', 'defaultRole', value)} options={[[ 'user', 'User' ], [ 'creator', 'Creator' ]]} saved={savedSettingField === 'auth.registration.defaultRole'} />
            <EditableSelect label="Verification required" value={authForm.registration?.confirmationMethod || 'none'} onChange={(value) => setAuthValue('registration', 'confirmationMethod', value)} options={[['none', 'No verification'], ['email', 'Verify by email'], ['sms', 'Verify by SMS']]} saved={savedSettingField === 'auth.registration.confirmationMethod'} />
            <EditableToggleField label="Collect phone number" checked={(authForm.registration?.phoneEnabled ?? false) || authForm.registration?.confirmationMethod === 'sms'} onChange={(value) => setAuthValue('registration', 'phoneEnabled', value)} saved={savedSettingField === 'auth.registration.phoneEnabled'} disabled={authForm.registration?.confirmationMethod === 'sms'} />
            <EditableToggleField label="Require username" checked={authForm.registration?.usernameRequired ?? false} onChange={(value) => setAuthValue('registration', 'usernameRequired', value)} saved={savedSettingField === 'auth.registration.usernameRequired'} />
            {['email', 'sms'].includes(authForm.registration?.confirmationMethod) && <>
              <EditableField label="Verification code digits" value={authForm.registration?.smsCodeDigits ?? 4} onChange={(value) => setAuthValue('registration', 'smsCodeDigits', value)} saved={savedSettingField === 'auth.registration.smsCodeDigits'} type="number" />
              <EditableField label="Verification code expires (minutes)" value={authForm.registration?.smsCodeExpiresMinutes ?? 5} onChange={(value) => setAuthValue('registration', 'smsCodeExpiresMinutes', value)} saved={savedSettingField === 'auth.registration.smsCodeExpiresMinutes'} type="number" />
              <EditableField label="Verification resend attempts" value={authForm.registration?.smsMaxResends ?? 3} onChange={(value) => setAuthValue('registration', 'smsMaxResends', value)} saved={savedSettingField === 'auth.registration.smsMaxResends'} type="number" />
              <EditableField label="Invalid code attempts" value={authForm.registration?.smsInvalidAttempts ?? 3} onChange={(value) => setAuthValue('registration', 'smsInvalidAttempts', value)} saved={savedSettingField === 'auth.registration.smsInvalidAttempts'} type="number" />
              <EditableField label="Invalid IP block (minutes)" value={authForm.registration?.smsInvalidBlockMinutes ?? 60} onChange={(value) => setAuthValue('registration', 'smsInvalidBlockMinutes', value)} saved={savedSettingField === 'auth.registration.smsInvalidBlockMinutes'} type="number" />
            </>}
            <EditableToggleField label="Password strength" checked={authForm.registration?.passwordStrengthEnabled ?? true} onChange={(value) => setAuthValue('registration', 'passwordStrengthEnabled', value)} saved={savedSettingField === 'auth.registration.passwordStrengthEnabled'} />
            {(authForm.registration?.passwordStrengthEnabled ?? true) && <EditableField label="Required strength (0-10)" value={authForm.registration?.passwordStrengthLevel ?? 0} onChange={(value) => setAuthValue('registration', 'passwordStrengthLevel', value)} saved={savedSettingField === 'auth.registration.passwordStrengthLevel'} type="number" />}
            <EditableToggleField label="Registration captcha" checked={authForm.registration?.captchaEnabled ?? false} onChange={(value) => setAuthValue('registration', 'captchaEnabled', value)} saved={savedSettingField === 'auth.registration.captchaEnabled'} />
            {(authForm.registration?.captchaEnabled ?? false) && <EditableSelect label="Captcha type" value={authForm.registration?.captchaProvider || 'simple'} onChange={(value) => setAuthValue('registration', 'captchaProvider', value)} options={[['simple', 'Simple PHP captcha'], ['google', 'Google reCAPTCHA']]} saved={savedSettingField === 'auth.registration.captchaProvider'} />}
            {(authForm.registration?.captchaEnabled ?? false) && authForm.registration?.captchaProvider === 'google' && <>
              <EditableField label="reCAPTCHA site key" value={authForm.registration?.googleCaptchaSiteKey || ''} onChange={(value) => setAuthValue('registration', 'googleCaptchaSiteKey', value)} saved={savedSettingField === 'auth.registration.googleCaptchaSiteKey'} />
              <EditableField label="reCAPTCHA secret key" value={authForm.registration?.googleCaptchaSecretKey || ''} onChange={(value) => setAuthValue('registration', 'googleCaptchaSecretKey', value)} saved={savedSettingField === 'auth.registration.googleCaptchaSecretKey'} type="password" />
            </>}
            <EditableTextarea label="Blocked registration IPs" value={authForm.registration?.blockedIps || ''} onChange={(value) => setAuthValue('registration', 'blockedIps', value)} saved={savedSettingField === 'auth.registration.blockedIps'} placeholder="100.25.&#10;203.0.113.44&#10;198.51.100.*" />
            <button type="button" className="admin-outline-action primary-text" onClick={saveAuthSettings} disabled={authSaving}><AdminIcon name="shield" size={16} />{authSaving ? 'Saving...' : 'Save Registration'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-login')} title="Login" description="Choose allowed login identifiers and failed-login lockout behavior.">
            <EditableCheckboxField label="Login via email" checked={authForm.login?.email ?? true} onChange={(value) => setAuthValue('login', 'email', value)} saved={savedSettingField === 'auth.login.email'} />
            <EditableCheckboxField label="Login via username" checked={authForm.login?.username ?? true} onChange={(value) => setAuthValue('login', 'username', value)} saved={savedSettingField === 'auth.login.username'} />
            <EditableCheckboxField label="Login via phone number" checked={authForm.login?.phone ?? true} onChange={(value) => setAuthValue('login', 'phone', value)} saved={savedSettingField === 'auth.login.phone'} />
            <EditableToggleField label="Two-factor authentication" checked={authForm.login?.twoFactorEnabled ?? true} onChange={(value) => setAuthValue('login', 'twoFactorEnabled', value)} saved={savedSettingField === 'auth.login.twoFactorEnabled'} />
            <EditableField label="Bad login attempts" value={authForm.login?.maxAttempts ?? 5} onChange={(value) => setAuthValue('login', 'maxAttempts', value)} saved={savedSettingField === 'auth.login.maxAttempts'} type="number" />
            <EditableField label="Captcha after bad logins" value={authForm.login?.captchaAfterAttempts ?? 3} onChange={(value) => setAuthValue('login', 'captchaAfterAttempts', value)} saved={savedSettingField === 'auth.login.captchaAfterAttempts'} type="number" />
            <EditableField label="Ban time (minutes)" value={authForm.login?.banMinutes ?? 15} onChange={(value) => setAuthValue('login', 'banMinutes', value)} saved={savedSettingField === 'auth.login.banMinutes'} type="number" />
            <button type="button" className="admin-outline-action primary-text" onClick={saveAuthSettings} disabled={authSaving}><AdminIcon name="shield" size={16} />{authSaving ? 'Saving...' : 'Save Login'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-captcha')} title="Captcha and bot protection" description="Choose when a challenge is required and let enabled providers rotate at random. Native apps only support the image captcha and reCAPTCHA v2.">
            <div className="admin-settings-delimiter"><span>When to require a challenge</span></div>
            <EditableSelect label="Registration" value={authForm.captcha?.registrationScope || 'off'} onChange={(value) => setAuthCaptchaValue('registrationScope', value)} options={[['off', 'Off'], ['risk', 'Only at elevated risk'], ['always', 'Always']]} saved={savedSettingField === 'auth.captcha.registrationScope'} />
            <EditableSelect label="Login" value={authForm.captcha?.loginScope || 'risk'} onChange={(value) => setAuthCaptchaValue('loginScope', value)} options={[['off', 'Off'], ['risk', 'Only at elevated risk'], ['always', 'Always']]} saved={savedSettingField === 'auth.captcha.loginScope'} />
            <EditableField label="Risk score threshold" value={authForm.captcha?.riskThreshold ?? 6} onChange={(value) => setAuthCaptchaValue('riskThreshold', value)} saved={savedSettingField === 'auth.captcha.riskThreshold'} type="number" min="1" max="50" />
            <EditableField label="Challenge lifetime (seconds)" value={authForm.captcha?.challengeTtlSeconds ?? 600} onChange={(value) => setAuthCaptchaValue('challengeTtlSeconds', value)} saved={savedSettingField === 'auth.captcha.challengeTtlSeconds'} type="number" min="60" max="3600" />
            <EditableField label="Failed checks before a new challenge" value={authForm.captcha?.maxAttempts ?? 5} onChange={(value) => setAuthCaptchaValue('maxAttempts', value)} saved={savedSettingField === 'auth.captcha.maxAttempts'} type="number" min="1" max="20" />
            <div className="admin-settings-delimiter"><span>Providers and rotation weights</span></div>
            {captchaProviderFields.map(([id, label, needsKeys, scored]) => {
              const provider = authForm.captcha?.providers?.[id] || {};
              return <div className="admin-captcha-provider" key={id}>
                <EditableToggleField label={label} checked={provider.enabled ?? false} onChange={(value) => setAuthCaptchaProviderValue(id, 'enabled', value)} saved={savedSettingField === `auth.captcha.providers.${id}.enabled`} />
                <EditableField label="Rotation weight" value={provider.weight ?? 1} onChange={(value) => setAuthCaptchaProviderValue(id, 'weight', value)} saved={savedSettingField === `auth.captcha.providers.${id}.weight`} type="number" min="1" max="10" />
                {needsKeys && <>
                  <EditableField label="Site key" value={provider.siteKey || ''} onChange={(value) => setAuthCaptchaProviderValue(id, 'siteKey', value)} saved={savedSettingField === `auth.captcha.providers.${id}.siteKey`} wide />
                  <EditableField label="Secret key" value={provider.secretKey || ''} onChange={(value) => setAuthCaptchaProviderValue(id, 'secretKey', value)} saved={savedSettingField === `auth.captcha.providers.${id}.secretKey`} type="password" placeholder={provider.secretKeyConfigured ? 'Secret configured. Leave blank to keep the existing secret.' : ''} wide />
                </>}
                {scored && <EditableField label="Minimum v3 score (0-1)" value={provider.threshold ?? 0.5} onChange={(value) => setAuthCaptchaProviderValue(id, 'threshold', value)} saved={savedSettingField === `auth.captcha.providers.${id}.threshold`} type="number" step="0.1" min="0" max="1" />}
              </div>;
            })}
            <div className="admin-settings-delimiter"><span>Question captcha</span></div>
            <EditableTextarea label="Questions and answers (one per line: question | answer)" value={authForm.captcha?.questionsText ?? captchaQuestionsText(authForm.captcha?.questions)} onChange={(value) => setAuthCaptchaValue('questionsText', value)} placeholder="What color is the sky on a clear day? | blue" />
            <small className="admin-settings-note">An enabled external provider receives the visitor IP address and usage data for the challenge. Keep the privacy policy disclosure in sync. A missing provider configuration is skipped instead of locking visitors out.</small>
            <button type="button" className="admin-outline-action primary-text" onClick={saveAuthSettings} disabled={authSaving}><AdminIcon name="shield" size={16} />{authSaving ? 'Saving...' : 'Save Captcha'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-social-login')} title="Google, Facebook and Apple sign-in" description="Enable Google, Facebook and Apple OAuth for login and registration.">
            <div className="admin-settings-delimiter"><span>Google</span></div>
            <EditableToggleField label="Google Sign-In" checked={googleAuthForm.enabled ?? false} onChange={(value) => setAuthProviderValue('google', 'enabled', value)} saved={savedSettingField === 'auth.social.google.enabled'} />
            <EditableField label="Google Client ID" value={googleAuthForm.clientId || ''} onChange={(value) => setAuthProviderValue('google', 'clientId', value)} saved={savedSettingField === 'auth.social.google.clientId'} wide />
            <EditableField label="Google Client Secret" value={googleAuthForm.clientSecret || ''} onChange={(value) => setAuthProviderValue('google', 'clientSecret', value)} saved={savedSettingField === 'auth.social.google.clientSecret'} type="password" placeholder={googleAuthForm.clientSecretConfigured ? 'Client secret configured. Leave blank to keep existing secret.' : ''} wide />
            <Field label="Google redirect URI" value={googleAuthForm.redirectUrl || '/api/auth/social/google/callback'} wide />
            <div className="admin-settings-delimiter"><span>Facebook</span></div>
            <EditableToggleField label="Facebook Login" checked={facebookAuthForm.enabled ?? false} onChange={(value) => setAuthProviderValue('facebook', 'enabled', value)} saved={savedSettingField === 'auth.social.facebook.enabled'} />
            <EditableField label="Facebook App ID" value={facebookAuthForm.clientId || ''} onChange={(value) => setAuthProviderValue('facebook', 'clientId', value)} saved={savedSettingField === 'auth.social.facebook.clientId'} wide />
            <EditableField label="Facebook App Secret" value={facebookAuthForm.clientSecret || ''} onChange={(value) => setAuthProviderValue('facebook', 'clientSecret', value)} saved={savedSettingField === 'auth.social.facebook.clientSecret'} type="password" placeholder={facebookAuthForm.clientSecretConfigured ? 'Client secret configured. Leave blank to keep existing secret.' : ''} wide />
            <Field label="Facebook redirect URI" value={facebookAuthForm.redirectUrl || '/api/auth/social/facebook/callback'} wide />
            <div className="admin-settings-delimiter"><span>Apple</span></div>
            <EditableToggleField label="Apple Sign-In" checked={appleAuthForm.enabled ?? false} onChange={(value) => setAuthProviderValue('apple', 'enabled', value)} saved={savedSettingField === 'auth.social.apple.enabled'} />
            <EditableField label="Apple Services ID" value={appleAuthForm.clientId || ''} onChange={(value) => setAuthProviderValue('apple', 'clientId', value)} saved={savedSettingField === 'auth.social.apple.clientId'} wide />
            <EditableField label="Apple Team ID" value={appleAuthForm.teamId || ''} onChange={(value) => setAuthProviderValue('apple', 'teamId', value)} saved={savedSettingField === 'auth.social.apple.teamId'} />
            <EditableField label="Apple Key ID" value={appleAuthForm.keyId || ''} onChange={(value) => setAuthProviderValue('apple', 'keyId', value)} saved={savedSettingField === 'auth.social.apple.keyId'} />
            <EditableTextarea label="Apple private key (.p8)" value={appleAuthForm.privateKey || ''} onChange={(value) => setAuthProviderValue('apple', 'privateKey', value)} saved={savedSettingField === 'auth.social.apple.privateKey'} placeholder={appleAuthForm.privateKeyConfigured ? 'Private key configured. Leave blank to keep existing key.' : '-----BEGIN PRIVATE KEY-----'} />
            <Field label="Apple redirect URI" value={appleAuthForm.redirectUrl || '/api/auth/social/apple/callback'} wide />
            <button type="button" className="admin-outline-action primary-text" onClick={saveAuthSettings} disabled={authSaving}><AdminIcon name="shield" size={16} />{authSaving ? 'Saving...' : 'Save Social Login'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-publishing-access')} title="Publishing Access" description="Control who can upload videos and start live streams by account role or an explicit username allowlist.">
            <div className="admin-settings-delimiter"><span>VIDEO UPLOAD ROLES</span></div>
            {[['user','User'],['creator','Creator'],['moderator','Moderator'],['admin','Admin']].map(([role,label]) => <EditableCheckboxField key={`upload-${role}`} label={label} checked={(publishingSettings.uploadRoles || ['creator','moderator','admin']).includes(role)} onChange={(checked) => togglePublishingRole('upload', role, checked)} saved={savedSettingField === `features.publishing.uploadRoles.${role}`} />)}
            <div className="admin-settings-delimiter"><span>GO LIVE ROLES</span></div>
            {[['user','User'],['creator','Creator'],['moderator','Moderator'],['admin','Admin']].map(([role,label]) => <EditableCheckboxField key={`live-${role}`} label={label} checked={(publishingSettings.liveRoles || ['creator','moderator','admin']).includes(role)} onChange={(checked) => togglePublishingRole('live', role, checked)} saved={savedSettingField === `features.publishing.liveRoles.${role}`} />)}
            <EditableField label="Only allow these users" value={publishingSettings.allowedUsers || ''} onChange={(value) => setFeatureValue('publishing', 'allowedUsers', value)} saved={savedSettingField === 'features.publishing.allowedUsers'} placeholder="@admin, @john" suffix="Leave empty to use the role rules. When populated, only these usernames can upload and go live." wide />
            <button type="button" className="admin-outline-action primary-text" onClick={saveFeatureSettings} disabled={featureSaving}><AdminIcon name="shield" size={16} />{featureSaving ? 'Saving...' : 'Save Publishing Access'}</button>

            <div className="admin-settings-delimiter"><span>TIMED USER RESTRICTIONS</span></div>
            <div className="admin-publishing-restriction-form">
              <EditableField label="Username" value={publishingRestrictionForm.username} onChange={(value) => setPublishingRestrictionForm((current) => ({ ...current, username: value }))} placeholder="@username" />
              <EditableSelect label="Duration" value={publishingRestrictionForm.duration} onChange={(value) => setPublishingRestrictionForm((current) => ({ ...current, duration: value }))} options={[["1_day","1 day"],["1_week","1 week"],["1_month","1 month"],["3_months","3 months"],["6_months","6 months"],["forever","Forever"]]} />
              <div className="admin-publishing-restriction-scopes">
                <EditableCheckboxField label="Disable uploads" checked={publishingRestrictionForm.scopes.includes('uploads')} onChange={(checked) => togglePublishingRestrictionScope('uploads', checked)} />
                <EditableCheckboxField label="Disable go live" checked={publishingRestrictionForm.scopes.includes('live')} onChange={(checked) => togglePublishingRestrictionScope('live', checked)} />
              </div>
              <button type="button" className="admin-outline-action danger-text" onClick={applyPublishingRestriction} disabled={publishingRestrictionBusy || !publishingRestrictionForm.username.trim() || !publishingRestrictionForm.scopes.length}><AdminIcon name="ban" size={16} />{publishingRestrictionBusy ? 'Applying...' : 'Apply Restriction'}</button>
            </div>
            <div className="admin-publishing-restrictions">
              {publishingRestrictionsLoading ? <p>Loading restrictions...</p> : publishingRestrictions.length ? publishingRestrictions.map((restriction) => <div key={restriction.user_id} className="admin-publishing-restriction-row">
                <AdminAvatar name={restriction.name} image={restriction.avatar_url} tone="mono" />
                <span><strong>{restriction.name}</strong><small>@{restriction.username}</small></span>
                <div>{(restriction.restrictions || []).map((item) => <em key={item.scope}>{item.label} · {item.expires_label}</em>)}</div>
                <button type="button" className="admin-icon-button" onClick={() => clearPublishingRestriction(restriction)} disabled={publishingRestrictionBusy} aria-label={`Clear restrictions for ${restriction.username}`} title="Clear publishing restriction"><AdminIcon name="trash" size={16} /></button>
              </div>) : <p>No active upload or live restrictions.</p>}
            </div>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-live-streaming')} title="Live Streaming" description="Manage the public live product, creator tools, defaults, limits, and monetization.">
            <EditableToggleField label="Live Streaming Studio" checked={liveStreamingForm.enabled ?? true} onChange={(value) => setLiveStreamingRootValue('enabled', value)} saved={savedSettingField === 'live.enabled'} />
            <div className="admin-settings-delimiter"><span>Creator features</span></div>
            {[
              ['webcam_preview','Webcam preview'],['scheduling','Scheduling'],['thumbnails','Live thumbnails'],['appearance','Appearance controls'],
              ['chat','Live chat'],['replays','Save replay'],['ads','Live ads'],
              ['super_chat','Super Chat'],['super_stickers','Super Stickers'],['gifts','Gifts'],
              ['tips','Tips'],['goal_bar','Goal bar'],['polls','Polls'],['pinned_messages','Pinned messages'],['alerts','Alerts'],
              ['stream_labels','Stream labels'],['advanced_settings','Advanced settings'],
            ].map(([key,label]) => <EditableToggleField key={key} label={label} checked={liveStreamingForm.features?.[key] ?? false} onChange={(value) => setLiveStreamingValue('features', key, value)} saved={savedSettingField === `live.features.${key}`} />)}
            <div className="admin-settings-delimiter"><span>Allowed quality and latency</span></div>
            {[['auto','Auto'],['480p','480p'],['720p','720p'],['1080p','1080p'],['1440p','1440p'],['2160p','2160p']].map(([value,label]) => <EditableCheckboxField key={value} label={label} checked={(liveStreamingForm.options?.resolutions || []).includes(value)} onChange={(checked) => toggleLiveStreamingOption('resolutions', value, checked)} />)}
            {[['normal','Normal latency'],['low','Low latency'],['ultra_low','Ultra-low latency']].map(([value,label]) => <EditableCheckboxField key={value} label={label} checked={(liveStreamingForm.options?.latency_modes || []).includes(value)} onChange={(checked) => toggleLiveStreamingOption('latency_modes', value, checked)} />)}
            {[30,60].map((value) => <EditableCheckboxField key={value} label={`${value} fps`} checked={(liveStreamingForm.options?.frame_rates || []).includes(value)} onChange={(checked) => toggleLiveStreamingOption('frame_rates', value, checked)} />)}
            <div className="admin-settings-delimiter"><span>Replay retention</span></div>
            {[['off','Off'],['24_hours','24 hours'],['7_days','7 days'],['30_days','30 days'],['90_days','90 days'],['forever','Keep forever']].map(([value,label]) => <EditableCheckboxField key={value} label={label} checked={(liveStreamingForm.options?.replay_retention || []).includes(value)} onChange={(checked) => toggleLiveStreamingOption('replay_retention', value, checked)} />)}
            <div className="admin-settings-delimiter"><span>Audience and moderation</span></div>
            {[['everyone','Everyone'],['signed_in','Signed-in viewers'],['subscribers','Subscribers'],['invited','Invited viewers'],['private','Private']].map(([value,label]) => <EditableCheckboxField key={value} label={label} checked={(liveStreamingForm.options?.audience_modes || []).includes(value)} onChange={(checked) => toggleLiveStreamingOption('audience_modes', value, checked)} />)}
            {[['off','Moderation off'],['basic','Basic moderation'],['standard','Standard moderation'],['strict','Strict moderation'],['custom','Custom moderation']].map(([value,label]) => <EditableCheckboxField key={value} label={label} checked={(liveStreamingForm.options?.moderation_levels || []).includes(value)} onChange={(checked) => toggleLiveStreamingOption('moderation_levels', value, checked)} />)}
            {[5,10,30,60,120].map((value) => <EditableCheckboxField key={value} label={`${value} second slow mode`} checked={(liveStreamingForm.options?.slow_mode_intervals || []).includes(value)} onChange={(checked) => toggleLiveStreamingOption('slow_mode_intervals', value, checked)} />)}
            <div className="admin-settings-delimiter"><span>Creator defaults</span></div>
            <EditableSelect label="Default resolution" value={liveStreamingForm.defaults?.resolution || 'auto'} onChange={(value) => setLiveStreamingValue('defaults','resolution',value)} options={[['auto','Auto'],['480p','480p'],['720p','720p'],['1080p','1080p'],['1440p','1440p'],['2160p','2160p']].filter(([value]) => (liveStreamingForm.options?.resolutions || []).includes(value))} />
            <EditableSelect label="Default latency" value={liveStreamingForm.defaults?.latency_mode || 'low'} onChange={(value) => setLiveStreamingValue('defaults','latency_mode',value)} options={[['normal','Normal latency'],['low','Low latency'],['ultra_low','Ultra-low latency']].filter(([value]) => (liveStreamingForm.options?.latency_modes || []).includes(value))} />
            <EditableSelect label="Default replay retention" value={liveStreamingForm.defaults?.replay_retention || '7_days'} onChange={(value) => setLiveStreamingValue('defaults','replay_retention',value)} options={[['off','Off'],['24_hours','24 hours'],['7_days','7 days'],['30_days','30 days'],['90_days','90 days'],['forever','Keep forever']].filter(([value]) => (liveStreamingForm.options?.replay_retention || []).includes(value))} />
            <EditableSelect label="Default audience" value={liveStreamingForm.defaults?.audience_mode || 'everyone'} onChange={(value) => setLiveStreamingValue('defaults','audience_mode',value)} options={[['everyone','Everyone'],['signed_in','Signed-in viewers'],['subscribers','Subscribers'],['invited','Invited viewers'],['private','Private']].filter(([value]) => (liveStreamingForm.options?.audience_modes || []).includes(value))} />
            <EditableSelect label="Default moderation" value={liveStreamingForm.defaults?.moderation_level || 'standard'} onChange={(value) => setLiveStreamingValue('defaults','moderation_level',value)} options={[['off','Off'],['basic','Basic'],['standard','Standard'],['strict','Strict'],['custom','Custom']].filter(([value]) => (liveStreamingForm.options?.moderation_levels || []).includes(value))} />
            <EditableSelect label="Default slow mode interval" value={liveStreamingForm.defaults?.slow_mode_interval ?? 10} onChange={(value) => setLiveStreamingValue('defaults','slow_mode_interval',Number(value))} options={(liveStreamingForm.options?.slow_mode_intervals || []).map((value) => [value, `${value} seconds`])} />
            <EditableSelect label="Default frame rate" value={liveStreamingForm.defaults?.frame_rate ?? 30} onChange={(value) => setLiveStreamingValue('defaults','frame_rate',Number(value))} options={(liveStreamingForm.options?.frame_rates || []).map((value) => [value, `${value} fps`])} />
            <div className="admin-settings-delimiter"><span>Scheduling and tool limits</span></div>
            <EditableToggleField label="Strict scheduled start" checked={liveStreamingForm.schedule?.strict_start ?? true} onChange={(value) => setLiveStreamingValue('schedule','strict_start',value)} />
            <EditableField label="Maximum schedule horizon (days)" type="number" value={liveStreamingForm.limits?.schedule_max_days ?? 365} onChange={(value) => setLiveStreamingValue('limits','schedule_max_days',Number(value))} />
            <EditableField label="Thumbnail limit (MB)" type="number" value={liveStreamingForm.limits?.thumbnail_max_mb ?? 5} onChange={(value) => setLiveStreamingValue('limits','thumbnail_max_mb',Number(value))} />
            <EditableField label="Maximum poll options" type="number" value={liveStreamingForm.limits?.poll_max_options ?? 6} onChange={(value) => setLiveStreamingValue('limits','poll_max_options',Number(value))} />
            <EditableField label="Maximum poll duration (minutes)" type="number" value={liveStreamingForm.limits?.poll_max_duration_minutes ?? 1440} onChange={(value) => setLiveStreamingValue('limits','poll_max_duration_minutes',Number(value))} />
            <EditableField label="Maximum stream labels" type="number" value={liveStreamingForm.limits?.stream_label_max_count ?? 8} onChange={(value) => setLiveStreamingValue('limits','stream_label_max_count',Number(value))} />
            <EditableField label="Connection probe (KB)" type="number" value={liveStreamingForm.limits?.connection_probe_kb ?? 512} onChange={(value) => setLiveStreamingValue('limits','connection_probe_kb',Number(value))} />
            <EditableField label="Good upload threshold (Mbps)" type="number" value={liveStreamingForm.limits?.connection_good_mbps ?? 5} onChange={(value) => setLiveStreamingValue('limits','connection_good_mbps',Number(value))} />
            <EditableField label="Good latency threshold (ms)" type="number" value={liveStreamingForm.limits?.connection_good_latency_ms ?? 2000} onChange={(value) => setLiveStreamingValue('limits','connection_good_latency_ms',Number(value))} />
            <div className="admin-settings-delimiter"><span>Live monetization</span></div>
            <EditableField label={`Minimum tip (${String(liveStreamingForm.currency || 'usd').toUpperCase()})`} type="number" value={liveStreamingForm.monetization?.tip_min ?? '1.00'} onChange={(value) => setLiveStreamingValue('monetization','tip_min',value)} />
            <EditableField label={`Maximum tip (${String(liveStreamingForm.currency || 'usd').toUpperCase()})`} type="number" value={liveStreamingForm.monetization?.tip_max ?? '500.00'} onChange={(value) => setLiveStreamingValue('monetization','tip_max',value)} />
            <EditableField label="Contribution platform fee (%)" type="number" value={liveStreamingForm.monetization?.contribution_fee_percent ?? 0} onChange={(value) => setLiveStreamingValue('monetization','contribution_fee_percent',Number(value))} />
            <EditableField label={`Gross value per completed live ad (${String(liveStreamingForm.currency || 'usd').toUpperCase()})`} type="number" value={liveStreamingForm.monetization?.ad_gross ?? '0.0000'} onChange={(value) => setLiveStreamingValue('monetization','ad_gross',value)} />
            <EditableField label="Creator live ad share (%)" type="number" value={liveStreamingForm.monetization?.ad_creator_share_percent ?? 0} onChange={(value) => setLiveStreamingValue('monetization','ad_creator_share_percent',Number(value))} />
            <button type="button" className="admin-outline-action primary-text" onClick={() => saveLiveStreamingSettings('product')} disabled={liveStreamingSaving}><AdminIcon name="live" size={16} />{liveStreamingSaving ? 'Saving...' : 'Save Live Streaming Settings'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-streaming-engine')} title="Streaming Engine" description="Configure LiveKit connectivity, RTMP ingress, network ports, SSL, TURN, and server YAML.">
            <EditableToggleField label="Enable LiveKit server" checked={liveKitForm.enabled ?? true} onChange={(value) => setLiveStreamingValue('livekit', 'enabled', value)} />
            <EditableField label="LiveKit WebSocket URL" value={liveKitForm.url || ''} onChange={(value) => setLiveStreamingValue('livekit', 'url', value)} placeholder="wss://your-domain.com/livekit" wide />
            <EditableField label="LiveKit API key" value={liveKitForm.api_key || ''} onChange={(value) => setLiveStreamingValue('livekit', 'api_key', value)} wide />
            <EditableField label="LiveKit API secret" type="password" value={liveKitForm.api_secret || ''} onChange={(value) => setLiveStreamingValue('livekit', 'api_secret', value)} placeholder={liveKitForm.api_secret_configured ? 'API secret configured. Leave blank to keep the current secret.' : 'Minimum 32 characters'} wide />
            <EditableField label="Signaling / WebSocket port" type="number" value={liveKitForm.signal_port ?? 7880} onChange={(value) => setLiveStreamingValue('livekit', 'signal_port', Number(value))} suffix="The website reverse proxy terminates wss://" />
            <div className="admin-settings-delimiter"><span>RTMP ingress</span></div>
            <EditableField label="Public RTMP ingest URL" value={liveKitForm.rtmp_url || ''} onChange={(value) => setLiveStreamingValue('livekit', 'rtmp_url', value)} placeholder="rtmp://your-domain.com:1936/live" suffix="Base URL issued to creators and external encoders" wide />
            <EditableField label="RTMP ingress port" type="number" value={liveKitForm.rtmp_port ?? 1936} onChange={(value) => setLiveStreamingValue('livekit', 'rtmp_port', Number(value))} suffix="Open this TCP port for RTMP publishing" />
            <div className="admin-settings-delimiter"><span>LiveKit SSL certificates</span></div>
            <EditableToggleField label="Enable LiveKit SSL certificates" checked={liveKitForm.ssl_enabled ?? false} onChange={(value) => setLiveStreamingValue('livekit', 'ssl_enabled', value)} />
            <EditableField label="Host certificate directory" value={liveKitForm.certificate_directory || 'livekit/runtime/certs'} onChange={(value) => setLiveStreamingValue('livekit', 'certificate_directory', value)} placeholder="/absolute/path/to/certificates/example.com" suffix="Project-relative or any safe absolute server path; independent of OS control panel" wide />
            <EditableField label="Certificate domain" value={liveKitForm.ssl_domain || ''} onChange={(value) => setLiveStreamingValue('livekit', 'ssl_domain', value)} placeholder="your-domain.com" suffix="Used for WebSocket TLS and reused by TURN/TLS unless overridden" wide />
            <EditableField label="Certificate file path" value={liveKitForm.ssl_cert_path || ''} onChange={(value) => setLiveStreamingValue('livekit', 'ssl_cert_path', value)} placeholder="fullchain.pem" suffix="Relative to the certificate directory or absolute server path" wide />
            <EditableField label="Private key file path" value={liveKitForm.ssl_key_path || ''} onChange={(value) => setLiveStreamingValue('livekit', 'ssl_key_path', value)} placeholder="privkey.pem" suffix="Relative to the certificate directory or absolute server path" wide />
            <Field label="WebSocket TLS" value={liveKitForm.ssl_enabled ? 'Configured' : 'Not enabled'} suffix={liveKitForm.ssl_enabled ? 'Use this certificate in the website Nginx wss:// proxy for /livekit.' : 'Enable when the certificate files are present.'} />
            <div className="admin-settings-delimiter"><span>LiveKit TURN server</span></div>
            <Field label="TURN mode" value={liveKitForm.turn_tls_enabled ? 'TURN/TLS' : 'TURN/UDP'} suffix={liveKitForm.turn_tls_enabled ? 'TLS certificate mode' : 'Default non-TLS mode'} />
            <EditableToggleField label="TURN via TLS" checked={liveKitForm.turn_tls_enabled ?? false} onChange={(value) => setLiveStreamingValue('livekit', 'turn_tls_enabled', value)} />
            {liveKitForm.turn_tls_enabled ? <>
              <Field label="TURN/TLS certificate source" value={(liveKitForm.turn_tls_cert_path || liveKitForm.turn_tls_key_path) ? 'TURN-specific override' : 'Shared LiveKit SSL certificates'} suffix="Leave overrides empty to reuse the shared certificate above" />
              <EditableField label="TURN/TLS domain override" value={liveKitForm.turn_tls_domain || ''} onChange={(value) => setLiveStreamingValue('livekit', 'turn_tls_domain', value)} placeholder={liveKitForm.ssl_domain || 'turn.your-domain.com'} suffix="Optional; uses the shared certificate domain when empty" wide />
              <EditableField label="TURN/TLS port" type="number" value={liveKitForm.turn_tls_port || 5349} onChange={(value) => setLiveStreamingValue('livekit', 'turn_tls_port', Number(value))} suffix="Open this TCP port in the firewall when enabled" />
              <EditableField label="TURN certificate file override" value={liveKitForm.turn_tls_cert_path || ''} onChange={(value) => setLiveStreamingValue('livekit', 'turn_tls_cert_path', value)} placeholder={liveKitForm.ssl_cert_path || 'fullchain.pem'} suffix="Optional; relative to the certificate directory" wide />
              <EditableField label="TURN private key override" value={liveKitForm.turn_tls_key_path || ''} onChange={(value) => setLiveStreamingValue('livekit', 'turn_tls_key_path', value)} placeholder={liveKitForm.ssl_key_path || 'privkey.pem'} suffix="Optional; relative to the certificate directory" wide />
            </> : <EditableField label="TURN/UDP port" type="number" value={liveKitForm.turn_udp_port || 3478} onChange={(value) => setLiveStreamingValue('livekit', 'turn_udp_port', Number(value))} suffix="Default non-TLS TURN server port; open this UDP port in the firewall" />}
            <EditableTextarea code label="ICE server RTC configuration JSON" value={liveKitForm.ice_server_config_json || ''} onChange={(value) => setLiveStreamingValue('livekit', 'ice_server_config_json', value)} suffix="Paste an icesConfig JSON object. Replace TURN_IP_ADDRESS and PORT before using a custom TURN server." />
            <EditableField label="WebRTC TCP port" type="number" value={liveKitForm.rtc_tcp_port || 7881} onChange={(value) => setLiveStreamingValue('livekit', 'rtc_tcp_port', Number(value))} />
            <EditableField label="WebRTC UDP port range start" type="number" value={liveKitForm.rtc_udp_port_start || 50000} onChange={(value) => setLiveStreamingValue('livekit', 'rtc_udp_port_start', Number(value))} />
            <EditableField label="WebRTC UDP port range end" type="number" value={liveKitForm.rtc_udp_port_end || 60000} onChange={(value) => setLiveStreamingValue('livekit', 'rtc_udp_port_end', Number(value))} />
            <EditableToggleField label="Discover public IP using STUN" checked={liveKitForm.use_external_ip ?? true} onChange={(value) => setLiveStreamingValue('livekit', 'use_external_ip', value)} />
            <EditableField label="Public node IPv4 override" value={liveKitForm.node_ip || ''} onChange={(value) => setLiveStreamingValue('livekit', 'node_ip', value)} placeholder="Optional when STUN discovery is enabled" />
            <EditableField label="LiveKit region" value={liveKitForm.region || ''} onChange={(value) => setLiveStreamingValue('livekit', 'region', value)} placeholder="Optional region identifier" />
            <EditableSelect label="LiveKit log level" value={liveKitForm.log_level || 'info'} onChange={(value) => setLiveStreamingValue('livekit', 'log_level', value)} options={[["debug","Debug"],["info","Info"],["warn","Warning"],["error","Error"]]} />
            <EditableField label="Empty room timeout (seconds)" type="number" value={liveKitForm.room_empty_timeout || 300} onChange={(value) => setLiveStreamingValue('livekit', 'room_empty_timeout', Number(value))} />
            <EditableField label="Participant departure timeout (seconds)" type="number" value={liveKitForm.room_departure_timeout || 20} onChange={(value) => setLiveStreamingValue('livekit', 'room_departure_timeout', Number(value))} />
            <EditableField label="Maximum participants per room" type="number" value={liveKitForm.max_participants ?? 0} onChange={(value) => setLiveStreamingValue('livekit', 'max_participants', Number(value))} suffix="0 means unlimited" />
            <div className="admin-settings-delimiter"><span>LiveKit server configuration</span></div>
            <EditableSelect label="LiveKit configuration source" value={liveKitForm.livekit_config_mode || 'generated'} onChange={(value) => setLiveStreamingValue('livekit', 'livekit_config_mode', value)} options={[["generated","Generate from standard and advanced settings"],["custom","Use the custom LiveKit YAML below"]]} wide />
            <EditableTextarea code label="LiveKit server YAML" value={liveKitForm.livekit_config_yaml || ''} onChange={(value) => setLiveKitFileValue('livekit_config_yaml', 'livekit_config_mode', value)} suffix="Editing this file automatically switches LiveKit configuration source to Custom." />
            <div className="admin-settings-delimiter"><span>Advanced generated LiveKit options</span></div>
            <Field label="Advanced config merge" value="LiveKit v1.13.3 defaults" suffix={liveKitForm.livekit_config_mode === 'custom' ? 'Custom LiveKit YAML is active, so this JSON is not merged.' : 'Merged into the generated LiveKit YAML; standard fields override duplicates.'} />
            <EditableTextarea label="Advanced LiveKit config JSON" value={liveKitForm.advanced_config_json || ''} onChange={(value) => setLiveStreamingValue('livekit', 'advanced_config_json', value)} placeholder='{"rtc":{"stun_servers":[]}}' />
            {liveKitConnection && <Field label="LiveKit connection test" value={liveKitConnection.success ? 'Connected' : 'Failed'} suffix={[liveKitConnection.message, liveKitConnection.success && liveKitConnection.endpoint ? `${liveKitConnection.endpoint} · ${liveKitConnection.latency_ms || 0} ms · ${liveKitConnection.rooms || 0} rooms` : ''].filter(Boolean).join(' · ')} />}
            <div className="admin-settings-actions admin-livekit-actions">
              <button type="button" className="admin-outline-action primary-text" onClick={testLiveKitConnection} disabled={Boolean(liveKitAction)}><AdminIcon name="activity" size={15} />{liveKitAction === 'test' ? 'Testing connection...' : 'Test LiveKit connection'}</button>
            </div>
            <button type="button" className="admin-outline-action primary-text" onClick={() => saveLiveStreamingSettings('livekit')} disabled={liveStreamingSaving}><AdminIcon name="live" size={16} />{liveStreamingSaving ? 'Saving...' : 'Save Streaming Engine'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-docker-configuration')} title="Docker Configuration" description="Manage Compose files, runtime paths, container images, status, logs, and lifecycle controls.">
            <EditableField label="Docker Compose project" value={liveKitForm.docker_project_name || 'playhub-livekit'} onChange={(value) => setLiveStreamingValue('livekit', 'docker_project_name', value)} placeholder="playhub-livekit" />
            <div className="admin-settings-delimiter"><span>{`LIVEKIT SERVER / ${liveKitForm.docker_image || 'livekit/livekit-server:v1.13.3'}`}</span></div>
            <EditableField label="Docker image" value={liveKitForm.docker_image || 'livekit/livekit-server:v1.13.3'} onChange={(value) => setLiveStreamingValue('livekit', 'docker_image', value)} placeholder="livekit/livekit-server:v1.13.3" wide />
            <EditableField label="Docker container name" value={liveKitForm.container_name || 'playhub-livekit'} onChange={(value) => setLiveStreamingValue('livekit', 'container_name', value)} placeholder="playhub-livekit" />
            <Field label="Container status" value={liveKitStatusLabel} suffix={!liveKitDockerAvailable ? (liveKitForm.ready ? 'LiveKit service is reachable; Docker status unavailable' : 'Docker status unavailable') : liveKitDocker.stale ? 'Docker status snapshot is stale' : liveKitForm.ready ? 'WebRTC engine is accepting connections' : liveKitRunning ? 'Container is starting' : 'Container is not running'} />
            {liveKitContainer.error && <Field label="Docker configuration error" value={liveKitContainer.error} suffix="See livekit/runtime/status/docker-action.log for details" />}
            <Field label="Container ID" value={liveKitContainerId} suffix={`${liveKitContainer.restart_count || 0} restarts`} />
            <Field label="Image ID" value={liveKitImageId} suffix={[liveKitImageSize, liveKitImage.architecture ? `${liveKitImage.os || 'linux'}/${liveKitImage.architecture}` : ''].filter(Boolean).join(' · ')} />
            <div className="admin-settings-delimiter"><span>{`LIVEKIT INGRESS / ${liveKitForm.ingress_image || 'livekit/ingress:v1.5.0'}`}</span></div>
            <EditableField label="Ingress image" value={liveKitForm.ingress_image || 'livekit/ingress:v1.5.0'} onChange={(value) => setLiveStreamingValue('livekit', 'ingress_image', value)} placeholder="livekit/ingress:v1.5.0" wide />
            <EditableField label="Ingress container name" value={liveKitForm.ingress_container_name || 'playhub-livekit-ingress'} onChange={(value) => setLiveStreamingValue('livekit', 'ingress_container_name', value)} placeholder="playhub-livekit-ingress" />
            <Field label="Container status" value={liveKitIngressStatusLabel} suffix={!liveKitDockerAvailable ? 'Docker status unavailable' : liveKitDocker.stale ? 'Docker status snapshot is stale' : liveKitIngressRunning ? 'RTMP ingress is accepting publishers' : 'Ingress container is not running'} />
            {liveKitIngressContainer.error && <Field label="Ingress container error" value={liveKitIngressContainer.error} suffix="See livekit/runtime/status/docker-action.log for details" />}
            <Field label="Container ID" value={liveKitIngressContainerId} suffix={`${liveKitIngressContainer.restart_count || 0} restarts`} />
            <Field label="Image ID" value={liveKitIngressImageId} suffix={[liveKitIngressImageSize, liveKitIngressImage.architecture ? `${liveKitIngressImage.os || 'linux'}/${liveKitIngressImage.architecture}` : ''].filter(Boolean).join(' · ')} />
            <div className="admin-settings-delimiter"><span>{`LIVEKIT EGRESS / ${liveKitForm.egress_image || 'livekit/egress:v1.13.0'}`}</span></div>
            <EditableField label="Egress image" value={liveKitForm.egress_image || 'livekit/egress:v1.13.0'} onChange={(value) => setLiveStreamingValue('livekit', 'egress_image', value)} placeholder="livekit/egress:v1.13.0" wide />
            <EditableField label="Egress container name" value={liveKitForm.egress_container_name || 'playhub-livekit-egress'} onChange={(value) => setLiveStreamingValue('livekit', 'egress_container_name', value)} placeholder="playhub-livekit-egress" />
            <Field label="Container status" value={liveKitEgressStatusLabel} suffix={!liveKitDockerAvailable ? 'Docker status unavailable' : liveKitDocker.stale ? 'Docker status snapshot is stale' : liveKitEgressRunning ? 'Replay recording worker is ready' : 'Egress container is not running'} />
            {liveKitEgressContainer.error && <Field label="Egress container error" value={liveKitEgressContainer.error} suffix="See livekit/runtime/status/docker-action.log for details" />}
            <Field label="Container ID" value={liveKitEgressContainerId} suffix={`${liveKitEgressContainer.restart_count || 0} restarts`} />
            <Field label="Image ID" value={liveKitEgressImageId} suffix={[liveKitEgressImageSize, liveKitEgressImage.architecture ? `${liveKitEgressImage.os || 'linux'}/${liveKitEgressImage.architecture}` : ''].filter(Boolean).join(' · ')} />
            <Field label="Docker status updated" value={liveKitDockerUpdated} suffix={liveKitDockerAvailable ? (liveKitDocker.stale ? 'Snapshot is stale' : 'Project-local Docker snapshot') : 'Status heartbeat unavailable'} />
            <div className="admin-settings-actions admin-livekit-actions">
              <button type="button" className="admin-outline-action" onClick={() => runLiveKitAction('start')} disabled={Boolean(liveKitAction) || (liveKitRunning && liveKitIngressRunning && liveKitEgressRunning)}><AdminIcon name="play" size={15} />Start</button>
              <button type="button" className="admin-outline-action" onClick={() => runLiveKitAction('stop')} disabled={Boolean(liveKitAction) || (!liveKitRunning && !liveKitIngressRunning && !liveKitEgressRunning)}><AdminIcon name="pauseCircle" size={15} />Stop</button>
              <button type="button" className="admin-outline-action" onClick={() => runLiveKitAction('restart')} disabled={Boolean(liveKitAction) || (!liveKitRunning && !liveKitIngressRunning && !liveKitEgressRunning)}><AdminIcon name="refresh" size={15} />Restart</button>
              <button type="button" className="admin-outline-action" onClick={() => runLiveKitAction('recreate')} disabled={Boolean(liveKitAction)}><AdminIcon name="settings" size={15} />Apply configuration</button>
              <button type="button" className="admin-outline-action" onClick={() => runLiveKitAction('pull')} disabled={Boolean(liveKitAction)}><AdminIcon name="download" size={15} />Pull image</button>
              <button type="button" className="admin-outline-action" onClick={loadLiveKitLogs} disabled={Boolean(liveKitAction)}><AdminIcon name="eye" size={15} />{liveKitAction === 'logs' ? 'Loading logs...' : 'View logs'}</button>
            </div>
            {liveKitLogs !== null && <div className="admin-livekit-log-viewer"><header><strong>LiveKit and Docker logs</strong><button type="button" onClick={() => setLiveKitLogs(null)}><AdminIcon name="close" size={15} />Close</button></header><pre>{liveKitLogs}</pre></div>}
            <div className="admin-settings-delimiter"><span>Project-local LiveKit file paths</span></div>
            <Field label="Writable configuration root" value="livekit/runtime/" suffix="Every active host-side Docker and LiveKit configuration file stays inside PlayHub" />
            <Field label="LiveKit settings file" value={liveKitForm.settings_path || 'livekit/runtime/settings.json'} suffix="Fixed bootstrap path inside the project" />
            <EditableField label="Docker Compose file" value={liveKitForm.docker_compose_path || 'livekit/runtime/docker-compose.yml'} onChange={(value) => setLiveStreamingValue('livekit', 'docker_compose_path', value)} wide />
            <EditableField label="LiveKit YAML file" value={liveKitForm.livekit_config_path || 'livekit/runtime/livekit.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'livekit_config_path', value)} wide />
            <EditableField label="Ingress YAML file" value={liveKitForm.ingress_config_path || 'livekit/runtime/ingress.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'ingress_config_path', value)} wide />
            <EditableField label="Egress YAML file" value={liveKitForm.egress_config_path || 'livekit/runtime/egress.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'egress_config_path', value)} wide />
            <EditableField label="API keys file" value={liveKitForm.keys_path || 'livekit/runtime/keys.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'keys_path', value)} suffix="Managed by the API key and secret fields" wide />
            <EditableField label="Server restart trigger" value={liveKitForm.restart_trigger_path || 'livekit/runtime/restart.trigger'} onChange={(value) => setLiveStreamingValue('livekit', 'restart_trigger_path', value)} wide />
            <EditableField label="Docker apply trigger" value={liveKitForm.docker_apply_trigger_path || 'livekit/runtime/docker-apply.trigger'} onChange={(value) => setLiveStreamingValue('livekit', 'docker_apply_trigger_path', value)} wide />
            <EditableField label="Docker command trigger" value={liveKitForm.docker_command_path || 'livekit/runtime/docker-command.trigger'} onChange={(value) => setLiveStreamingValue('livekit', 'docker_command_path', value)} wide />
            <EditableField label="Docker entrypoint file" value={liveKitForm.entrypoint_path || 'livekit/runtime/entrypoint.sh'} onChange={(value) => setLiveStreamingValue('livekit', 'entrypoint_path', value)} wide />
            <EditableField label="Docker status file" value={liveKitForm.status_path || 'livekit/runtime/status/docker-status.json'} onChange={(value) => setLiveStreamingValue('livekit', 'status_path', value)} wide />
            <EditableField label="LiveKit log file" value={liveKitForm.log_path || 'livekit/runtime/logs/livekit.log'} onChange={(value) => setLiveStreamingValue('livekit', 'log_path', value)} wide />
            <div className="admin-settings-delimiter"><span>Docker container file paths</span></div>
            <EditableField label="Container LiveKit YAML" value={liveKitForm.container_livekit_config_path || '/playhub/livekit/runtime/livekit.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'container_livekit_config_path', value)} wide />
            <EditableField label="Container ingress YAML" value={liveKitForm.container_ingress_config_path || '/playhub/livekit/runtime/ingress.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'container_ingress_config_path', value)} wide />
            <EditableField label="Container egress YAML" value={liveKitForm.container_egress_config_path || '/playhub/livekit/runtime/egress.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'container_egress_config_path', value)} wide />
            <EditableField label="Container API keys file" value={liveKitForm.container_keys_path || '/playhub/livekit/runtime/keys.yaml'} onChange={(value) => setLiveStreamingValue('livekit', 'container_keys_path', value)} wide />
            <EditableField label="Container restart trigger" value={liveKitForm.container_restart_trigger_path || '/playhub/livekit/runtime/restart.trigger'} onChange={(value) => setLiveStreamingValue('livekit', 'container_restart_trigger_path', value)} wide />
            <EditableField label="Container entrypoint" value={liveKitForm.container_entrypoint_path || '/playhub/livekit/entrypoint.sh'} onChange={(value) => setLiveStreamingValue('livekit', 'container_entrypoint_path', value)} wide />
            <EditableField label="Container status file" value={liveKitForm.container_status_path || '/playhub/livekit/status/docker-status.json'} onChange={(value) => setLiveStreamingValue('livekit', 'container_status_path', value)} wide />
            <EditableField label="Container log file" value={liveKitForm.container_log_path || '/playhub/livekit/logs/livekit.log'} onChange={(value) => setLiveStreamingValue('livekit', 'container_log_path', value)} wide />
            <EditableField label="Container certificate directory" value={liveKitForm.container_certificate_directory || '/playhub/livekit/certificates'} onChange={(value) => setLiveStreamingValue('livekit', 'container_certificate_directory', value)} suffix="The host certificate directory is mounted here read-only" wide />
            <div className="admin-settings-delimiter"><span>Compose and runtime files</span></div>
            <EditableSelect label="Docker Compose source" value={liveKitForm.docker_compose_mode || 'generated'} onChange={(value) => setLiveStreamingValue('livekit', 'docker_compose_mode', value)} options={[["generated","Generate from the fields above"],["custom","Use the custom Compose YAML below"]]} wide />
            <EditableTextarea code label="Docker Compose YAML" value={liveKitForm.docker_compose_yaml || ''} onChange={(value) => setLiveKitFileValue('docker_compose_yaml', 'docker_compose_mode', value)} suffix="Editing this file automatically switches Docker Compose source to Custom." />
            <EditableTextarea code label="Docker entrypoint script" value={liveKitForm.entrypoint_script || ''} onChange={(value) => setLiveKitFileValue('entrypoint_script', null, value)} suffix="Saved as the configured project-local entrypoint file." />
            <button type="button" className="admin-outline-action primary-text" onClick={() => saveLiveStreamingSettings('livekit')} disabled={liveStreamingSaving}><AdminIcon name="live" size={16} />{liveStreamingSaving ? 'Saving...' : 'Save Docker Configuration'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-notifications')} title="Notifications" description="Configure system and user notifications.">
            <Field label="System Alerts Email" value={notifications.alertEmail || email.fromEmail || 'admin@example.com'} />
            <EditableToggleField label="New User Notifications" checked={notificationSettings.newUsers ?? true} onChange={(value) => setFeatureValue('notifications', 'newUsers', value)} saved={savedSettingField === 'features.notifications.newUsers'} />
            <EditableToggleField label="Weekly Digest" checked={notificationSettings.weeklyDigest ?? false} onChange={(value) => setFeatureValue('notifications', 'weeklyDigest', value)} saved={savedSettingField === 'features.notifications.weeklyDigest'} />
            <EditableToggleField label="Marketing Emails" checked={notificationSettings.marketingEmails ?? false} onChange={(value) => setFeatureValue('notifications', 'marketingEmails', value)} saved={savedSettingField === 'features.notifications.marketingEmails'} />
            <button type="button" className="admin-outline-action primary-text" onClick={saveFeatureSettings} disabled={featureSaving}><AdminIcon name="bell" size={16} />{featureSaving ? 'Saving...' : 'Save Notifications'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-storage')} title="Storage & CDN" description="Manage media storage and content delivery settings.">
            <div className="admin-integration-tabs">
              {storageProviderTabs.map((provider) => <IntegrationBadge key={provider.id} icon={provider.icon} name={provider.name} status={provider.status} active={activeStorageProvider === provider.id} wide={provider.wide} onClick={() => setStorageValue('mediaStorage', provider.id)} />)}
            </div>
            <Field label="Storage Total" value={storageTotalLabel} suffix={storageTotalSource} />
            {storageProvider !== 'default' && storageProvider !== 'local' && <EditableField label="Cloud Total Storage (GB)" value={storageForm.cloudStorageTotalGb ?? 0} onChange={(value) => setStorageValue('cloudStorageTotalGb', value)} saved={savedSettingField === 'storage.cloudStorageTotalGb'} type="number" />}
            <EditableField label="Records Prefix" value={storageForm.recordsPathPrefix || ''} onChange={(value) => setStorageValue('recordsPathPrefix', value)} saved={savedSettingField === 'storage.recordsPathPrefix'} />
            <StorageHelp>Use a relative object prefix. Changing the provider or prefix does not migrate existing files.</StorageHelp>
            {storageProvider !== 'default' && storageProvider !== 'local' && <>
              <EditableField label="Public Base URL" value={providerConfig.publicBaseUrl || ''} onChange={(value) => setProviderValue('publicBaseUrl', value)} saved={savedSettingField === `storage.${storageProvider}.publicBaseUrl`} wide />
              <StorageHelp>Optional CDN/bucket/container URL before the records prefix. Leave blank for provider URLs. Blank secret fields keep saved credentials; Clear removes a secret when saved.</StorageHelp>
            </>}
            {storageProvider === 's3' && <>
              <EditableField label="Endpoint" value={providerConfig.endpoint || ''} onChange={(value) => setProviderValue('endpoint', value)} saved={savedSettingField === 'storage.s3.endpoint'} wide />
              <StorageHelp>Leave the endpoint blank for AWS, or use your R2, Spaces, MinIO or other S3-compatible endpoint. Use the provider's signing region (auto for R2). Server IAM/default credentials ignore the key fields below.</StorageHelp>
              <EditableToggleField label="Use server IAM / default credentials" checked={providerConfig.useDefaultCredentials ?? false} onChange={(value) => setProviderValue('useDefaultCredentials', value)} />
              <EditableField label="Access Key" value={providerConfig.keyId || ''} onChange={(value) => setProviderValue('keyId', value)} saved={savedSettingField === 'storage.s3.keyId'} />
              {storageSecretField('Secret Key', 'secretAccessKey')}
              {storageSecretField('Session Token (optional)', 'sessionToken')}
              <EditableField label="Bucket" value={providerConfig.bucketName || ''} onChange={(value) => setProviderValue('bucketName', value)} saved={savedSettingField === 'storage.s3.bucketName'} />
              <EditableField label="Region" value={providerConfig.region || ''} onChange={(value) => setProviderValue('region', value)} saved={savedSettingField === 'storage.s3.region'} />
              <EditableSelect label="Bucket addressing" value={providerConfig.usePathStyleEndpoint == null ? 'auto' : providerConfig.usePathStyleEndpoint ? 'path' : 'virtual'} onChange={(value) => setProviderValue('usePathStyleEndpoint', value === 'auto' ? null : value === 'path')} options={[["auto","Auto (path for custom endpoints)"],["path","Path style: endpoint/bucket"],["virtual","Virtual host: bucket.endpoint"]]} />
            </>}
            {storageProvider === 'b2' && <>
              <EditableField label="Endpoint" value={providerConfig.endpoint || ''} onChange={(value) => setProviderValue('endpoint', value)} saved={savedSettingField === 'storage.b2.endpoint'} wide />
              <EditableField label="Key ID" value={providerConfig.keyId || ''} onChange={(value) => setProviderValue('keyId', value)} saved={savedSettingField === 'storage.b2.keyId'} />
              {storageSecretField('Application Key', 'masterApplicationKey')}
              <EditableField label="Bucket Name" value={providerConfig.bucketName || ''} onChange={(value) => setProviderValue('bucketName', value)} saved={savedSettingField === 'storage.b2.bucketName'} />
              <EditableField label="Region" value={providerConfig.region || ''} onChange={(value) => setProviderValue('region', value)} saved={savedSettingField === 'storage.b2.region'} />
              <StorageHelp>Use the bucket's S3 API region, for example us-west-004. An explicit Backblaze endpoint can also identify the region. Use an application key with read, write and delete permissions, not a master application key. This integration uses the bucket name, not the B2 bucket ID.</StorageHelp>
              <EditableToggleField label="Path-style bucket addressing" checked={providerConfig.usePathStyleEndpoint ?? false} onChange={(value) => setProviderValue('usePathStyleEndpoint', value)} />
            </>}
            {storageProvider === 'azure' && <>
              <EditableField label="Blob Endpoint" value={providerConfig.endpoint || ''} onChange={(value) => setProviderValue('endpoint', value)} wide />
              <EditableField label="Account Name" value={providerConfig.accountName || ''} onChange={(value) => setProviderValue('accountName', value)} saved={savedSettingField === 'storage.azure.accountName'} />
              {storageSecretField('Account Key', 'accountKey')}
              <EditableField label="Container" value={providerConfig.containerName || ''} onChange={(value) => setProviderValue('containerName', value)} saved={savedSettingField === 'storage.azure.containerName'} />
              {storageSecretField('Connection String (alternative)', 'connectionString', true)}
              <StorageHelp>A saved connection string overrides the account fields and Blob endpoint. Clear it to use account fields again. The optional Blob endpoint supports sovereign clouds, custom endpoints and Azurite. Use a shared key or an AccountKey connection string for signed private playback. SAS-only strings cannot mint fresh object-specific playback URLs; their granted access and expiry control availability.</StorageHelp>
            </>}
            {storageProvider === 'gcs' && <>
              <EditableField label="HMAC Access Key ID" value={providerConfig.accessKeyId || ''} onChange={(value) => setProviderValue('accessKeyId', value)} saved={savedSettingField === 'storage.gcs.accessKeyId'} />
              {storageSecretField('HMAC Secret Key', 'secretAccessKey')}
              <EditableField label="Bucket" value={providerConfig.bucketName || ''} onChange={(value) => setProviderValue('bucketName', value)} saved={savedSettingField === 'storage.gcs.bucketName'} />
              <EditableField label="XML API Endpoint" value={providerConfig.endpoint || ''} onChange={(value) => setProviderValue('endpoint', value)} placeholder="https://storage.googleapis.com" wide />
              <EditableField label="Signing Region" value={providerConfig.region || ''} onChange={(value) => setProviderValue('region', value)} placeholder="auto" />
              <EditableToggleField label="Path-style bucket addressing" checked={providerConfig.usePathStyleEndpoint ?? true} onChange={(value) => setProviderValue('usePathStyleEndpoint', value)} />
              <StorageHelp>Use Cloud Storage interoperability HMAC credentials, not a service-account JSON key. The default XML API endpoint is https://storage.googleapis.com and signing region is auto.</StorageHelp>
            </>}
            {['s3', 'gcs', 'b2'].includes(storageProvider) && <>
              <EditableToggleField label="Omit object ACLs (bucket-policy / uniform access)" checked={providerConfig.omitAcl ?? true} onChange={(value) => setProviderValue('omitAcl', value)} />
              <StorageHelp>Public access is controlled by the bucket policy or CDN; private playback uses expiring signed URLs. Disable ACL omission only if your provider requires legacy object ACLs.</StorageHelp>
            </>}
            <button type="button" className="admin-outline-action" onClick={testStorageSettings} disabled={storageTesting || storageSaving}><AdminIcon name="cloud" size={16} />{storageTesting ? 'Testing...' : 'Test Connection'}</button>
            <div className="admin-settings-delimiter"><span>PACKAGES CONFIGURATION</span></div>
            <EditableSelect label="Default Membership" value={storageForm.defaultMembership || 'starter'} onChange={(value) => setStorageValue('defaultMembership', value)} options={storageMembershipOptions} saved={savedSettingField === 'storage.defaultMembership'} />
            <EditableSelect label="Select to configure membership" value={selectedMembership} onChange={setStorageConfigMembership} options={storageMembershipOptions} />
            {selectedMembership === 'vip' ? <Field label="VIP quota" value="Unlimited" /> : <EditableField label={`${selectedMembershipLabel} quota (GB)`} value={membershipQuotas[selectedMembership] ?? storageMembershipQuotaDefaults[selectedMembership]} onChange={(value) => setStorageMembershipQuota(selectedMembership, value)} saved={savedSettingField === `storage.membershipQuotasGb.${selectedMembership}`} type="number" />}
            <EditableField label={`${selectedMembershipLabel} price / month`} value={membershipPrices[selectedMembership] ?? storageMembershipPriceDefaults[selectedMembership]} onChange={(value) => setStorageMembershipPrice(selectedMembership, value)} saved={savedSettingField === `storage.membershipPricesUsd.${selectedMembership}`} type="number" step="0.01" suffix={paymentCurrencyLabel} />
            <button type="button" className="admin-outline-action primary-text" onClick={saveStorageSettings} disabled={storageSaving || storageTesting}><AdminIcon name="cloud" size={16} />{storageSaving ? 'Saving...' : 'Save Storage'}</button>
          </SettingsRow>
          <SettingsRow id="setting-external-media" visible={selectedSettingsBlock.id === 'setting-storage'} title="External media services" description="Manage image hosters, private Oracle staging and Odysee publishing in one place.">
            <AdminMediaProviders api={api} alertAction={alertAction} platformName={data.general?.platformName || 'CrozzTube'}
              renderProvider={(props) => <IntegrationBadge {...props} />}
              renderIcon={() => <AdminIcon name="cloud" size={16} />}
              controls={{ Field: EditableField, Secret: StorageSecretField, Toggle: EditableToggleField, Select: EditableSelect }} />
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-uploads')} title="Uploads" description="Manage video processing qualities, player defaults, and uploader chapter tools.">
            <EditableToggleField label="Video chapters" checked={uploadSettings.chaptersEnabled ?? true} onChange={(value) => setFeatureValue('uploads', 'chaptersEnabled', value)} saved={savedSettingField === 'features.uploads.chaptersEnabled'} />
            <div className="admin-settings-delimiter"><span>GENERATED VIDEO QUALITIES</span></div>
            {[['original','Original'],['high','High'],['medium','Medium'],['low','Low']].map(([value,label]) => <EditableCheckboxField key={value} label={label} checked={(uploadSettings.renditions || ['original','high','medium','low']).includes(value)} onChange={(checked) => toggleUploadRendition(value, checked)} saved={savedSettingField === `features.uploads.renditions.${value}`} />)}
            <EditableSelect label="Default player quality" value={uploadSettings.defaultQuality || 'auto'} onChange={(value) => setFeatureValue('uploads', 'defaultQuality', value)} options={[['auto','Auto'],['original','Original'],['high','High'],['medium','Medium'],['low','Low']].filter(([value]) => value === 'auto' || (uploadSettings.renditions || ['original','high','medium','low']).includes(value))} saved={savedSettingField === 'features.uploads.defaultQuality'} />
            <button type="button" className="admin-outline-action primary-text" onClick={saveFeatureSettings} disabled={featureSaving}><AdminIcon name="cloud" size={16} />{featureSaving ? 'Saving...' : 'Save Upload Settings'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-video-import')} title="Video Import" description="Enable importing videos from supported external providers.">
            <div className="admin-settings-split-block">
              <div className="admin-settings-toggle-stack">
                <EditableToggleField label="Video Import" checked={videoImportForm.enabled ?? true} onChange={(value) => setVideoImportValue('enabled', value)} saved={savedSettingField === 'import.enabled'} />
                {videoImportProviders.map(([provider, label]) => {
                  const providerForm = (videoImportForm.providers || {})[provider] || {};
                  return <EditableToggleField key={provider} label={`${label} Provider`} checked={providerForm.enabled ?? false} onChange={(value) => setVideoImportProviderValue(provider, 'enabled', value)} saved={savedSettingField === `import.${provider}.enabled`} />;
                })}
              </div>
              <div className="admin-settings-field-grid">
                {videoImportProviders.map(([provider, , apiKeyLabel]) => {
                  const providerForm = (videoImportForm.providers || {})[provider] || {};
                  return apiKeyLabel ? <EditableField key={provider} label={apiKeyLabel} value={providerForm.apiKey || ''} onChange={(value) => setVideoImportProviderValue(provider, 'apiKey', value)} saved={savedSettingField === `import.${provider}.apiKey`} type="password" /> : null;
                })}
                <button type="button" className="admin-outline-action primary-text" onClick={saveVideoImportSettings} disabled={videoImportSaving}><AdminIcon name="cloud" size={16} />{videoImportSaving ? 'Saving...' : 'Save Import Settings'}</button>
              </div>
            </div>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-ads')} title="Player Ads" description="Control Generic VAST and Google IMA placements and the gross CPM used for completed-ad creator revenue sharing.">
            <EditableToggleField label="Player Ads" checked={playerAdForm.enabled ?? false} onChange={(value) => setPlayerAdValue('enabled', value)} saved={savedSettingField === 'ads.enabled'} />
            <EditableField label="Ad Load Timeout (seconds)" value={playerAdForm.loadTimeoutSeconds ?? 10} onChange={(value) => setPlayerAdValue('loadTimeoutSeconds', value)} saved={savedSettingField === 'ads.loadTimeoutSeconds'} type="number" />
            <button type="button" className="admin-outline-action" onClick={addPlayerAdItem}><AdminIcon name="ad" size={16} />Add Player Ad</button>
            {playerAdItems.map((ad, index) => <div className="admin-settings-ad-block" key={ad.id || index}>
              <EditableToggleField label="Ad Enabled" checked={ad.enabled ?? true} onChange={(value) => setPlayerAdItemValue(index, 'enabled', value)} saved={savedSettingField === `ads.${index}.enabled`} />
              <div className="admin-settings-field-grid">
                <EditableSelect label="Ad Engine" value={ad.engine || 'generic_vast'} onChange={(value) => setPlayerAdItemValue(index, 'engine', value)} options={[["generic_vast", 'Generic VAST'], ['google_ima', 'Google IMA SDK']]} saved={savedSettingField === `ads.${index}.engine`} />
                <EditableField label="Ad Name" value={ad.name || ''} onChange={(value) => setPlayerAdItemValue(index, 'name', value)} saved={savedSettingField === `ads.${index}.name`} />
                <EditableSelect label="Placement" value={ad.placement || 'preroll'} onChange={(value) => setPlayerAdItemValue(index, 'placement', value)} options={[["preroll", 'Pre-roll'], ['midroll', 'Mid-roll'], ['postroll', 'Post-roll']]} saved={savedSettingField === `ads.${index}.placement`} />
                {ad.placement === 'midroll' && <EditableField label="Play At Seconds" value={ad.offsetSeconds ?? 30} onChange={(value) => setPlayerAdItemValue(index, 'offsetSeconds', value)} saved={savedSettingField === `ads.${index}.offsetSeconds`} type="number" />}
                {(ad.engine || 'generic_vast') === 'generic_vast' && <EditableField label="Skip After Seconds" value={ad.skipAfter ?? 5} onChange={(value) => setPlayerAdItemValue(index, 'skipAfter', value)} saved={savedSettingField === `ads.${index}.skipAfter`} type="number" />}
                <EditableField label={`Gross CPM (${paymentCurrency})`} value={ad.grossCpm ?? 0} onChange={(value) => setPlayerAdItemValue(index, 'grossCpm', value)} saved={savedSettingField === `ads.${index}.grossCpm`} type="number" step="0.0001" />
                <EditableField label={(ad.engine || 'generic_vast') === 'google_ima' ? 'Google Ad Manager / IMA Ad Tag URL' : 'VAST Tag URL'} value={ad.vastUrl || ''} onChange={(value) => setPlayerAdItemValue(index, 'vastUrl', value)} saved={savedSettingField === `ads.${index}.vastUrl`} wide />
                <button type="button" className="admin-outline-action danger-text" onClick={() => removePlayerAdItem(index)}><AdminIcon name="trash" size={16} />Remove Ad</button>
              </div>
            </div>)}
            <button type="button" className="admin-outline-action primary-text" onClick={savePlayerAdSettings} disabled={playerAdSaving}><AdminIcon name="monetization" size={16} />{playerAdSaving ? 'Saving...' : 'Save Player Ads'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-smtp')} title="SMTP Email" description="Configure outgoing platform emails and email-based two-factor codes.">
            <EditableToggleField label="SMTP Email" checked={smtpForm.enabled ?? false} onChange={(value) => setIntegrationValue('smtp', 'enabled', value)} saved={savedSettingField === 'integration.smtp.enabled'} />
            <EditableField label="SMTP Host" value={smtpForm.host || ''} onChange={(value) => setIntegrationValue('smtp', 'host', value)} saved={savedSettingField === 'integration.smtp.host'} />
            <EditableField label="SMTP Port" value={smtpForm.port ?? 587} onChange={(value) => setIntegrationValue('smtp', 'port', value)} saved={savedSettingField === 'integration.smtp.port'} type="number" />
            <EditableSelect label="Encryption" value={smtpForm.encryption || 'tls'} onChange={(value) => setIntegrationValue('smtp', 'encryption', value)} options={[['none', 'None'], ['tls', 'TLS'], ['ssl', 'SSL']]} saved={savedSettingField === 'integration.smtp.encryption'} />
            <EditableField label="SMTP Username" value={smtpForm.username || ''} onChange={(value) => setIntegrationValue('smtp', 'username', value)} saved={savedSettingField === 'integration.smtp.username'} />
            <EditableField label="SMTP Password" value={smtpForm.password || ''} onChange={(value) => setIntegrationValue('smtp', 'password', value)} saved={savedSettingField === 'integration.smtp.password'} type="password" />
            <EditableField label="From Email" value={smtpForm.fromEmail || email.fromEmail || ''} onChange={(value) => setIntegrationValue('smtp', 'fromEmail', value)} saved={savedSettingField === 'integration.smtp.fromEmail'} />
            <EditableField label="From Name" value={smtpForm.fromName || configuredWebsiteName()} onChange={(value) => setIntegrationValue('smtp', 'fromName', value)} saved={savedSettingField === 'integration.smtp.fromName'} />
            <button type="button" className="admin-outline-action" onClick={testSmtpSettings} disabled={smtpTesting || integrationSaving}><AdminIcon name="mail" size={16} />{smtpTesting ? 'Sending...' : 'Test SMTP'}</button>
            <button type="button" className="admin-outline-action primary-text" onClick={saveIntegrationSettings} disabled={integrationSaving || smtpTesting}><AdminIcon name="mail" size={16} />{integrationSaving ? 'Saving...' : 'Save SMTP Email'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-sms')} title="SMS OTP" description="Configure SMS delivery for phone-based two-factor verification codes.">
            <EditableToggleField label="SMS OTP" checked={smsOtpForm.enabled ?? false} onChange={(value) => setIntegrationValue('smsOtp', 'enabled', value)} saved={savedSettingField === 'integration.smsOtp.enabled'} />
            <EditableSelect label="SMS Provider" value={smsOtpProvider} onChange={(value) => setIntegrationValue('smsOtp', 'provider', value)} options={smsOtpProviderOptions} saved={savedSettingField === 'integration.smsOtp.provider'} />
            {smsOtpProvider === 'twilio' && <>
              <EditableField label="Twilio Account SID" value={smsOtpForm.accountSid || ''} onChange={(value) => setIntegrationValue('smsOtp', 'accountSid', value)} saved={savedSettingField === 'integration.smsOtp.accountSid'} />
              <EditableField label="Twilio Auth Token" value={smsOtpForm.authToken || ''} onChange={(value) => setIntegrationValue('smsOtp', 'authToken', value)} saved={savedSettingField === 'integration.smsOtp.authToken'} type="password" />
              <EditableField label="Twilio From Number" value={smsOtpForm.fromNumber || ''} onChange={(value) => setIntegrationValue('smsOtp', 'fromNumber', value)} saved={savedSettingField === 'integration.smsOtp.fromNumber'} />
            </>}
            {smsOtpProvider === 'vonage' && <>
              <EditableField label="Vonage API Key" value={smsOtpForm.vonageApiKey || ''} onChange={(value) => setIntegrationValue('smsOtp', 'vonageApiKey', value)} saved={savedSettingField === 'integration.smsOtp.vonageApiKey'} />
              <EditableField label="Vonage API Secret" value={smsOtpForm.vonageApiSecret || ''} onChange={(value) => setIntegrationValue('smsOtp', 'vonageApiSecret', value)} saved={savedSettingField === 'integration.smsOtp.vonageApiSecret'} type="password" />
              <EditableField label="Vonage From Number" value={smsOtpForm.vonageFrom || ''} onChange={(value) => setIntegrationValue('smsOtp', 'vonageFrom', value)} saved={savedSettingField === 'integration.smsOtp.vonageFrom'} />
            </>}
            {smsOtpProvider === 'messagebird' && <>
              <EditableField label="MessageBird Access Key" value={smsOtpForm.messagebirdAccessKey || ''} onChange={(value) => setIntegrationValue('smsOtp', 'messagebirdAccessKey', value)} saved={savedSettingField === 'integration.smsOtp.messagebirdAccessKey'} type="password" />
              <EditableField label="MessageBird Originator" value={smsOtpForm.messagebirdOriginator || ''} onChange={(value) => setIntegrationValue('smsOtp', 'messagebirdOriginator', value)} saved={savedSettingField === 'integration.smsOtp.messagebirdOriginator'} />
            </>}
            <EditableField label="Test Phone Number" value={smsOtpForm.testPhone || ''} onChange={(value) => setIntegrationValue('smsOtp', 'testPhone', value)} saved={savedSettingField === 'integration.smsOtp.testPhone'} />
            <button type="button" className="admin-outline-action" onClick={testSmsOtpSettings} disabled={smsOtpTesting || integrationSaving}><AdminIcon name="mobile" size={16} />{smsOtpTesting ? 'Sending...' : 'Test SMS OTP'}</button>
            <button type="button" className="admin-outline-action primary-text" onClick={saveIntegrationSettings} disabled={integrationSaving || smsOtpTesting}><AdminIcon name="mobile" size={16} />{integrationSaving ? 'Saving...' : 'Save SMS OTP'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-moderation')} title="Moderation Defaults" description="Set default moderation and content policy settings.">
            <EditableSelect label="Auto-Approve Users" value={moderationSettings.autoApproveUsers || 'email_verified'} onChange={(value) => setFeatureValue('moderation', 'autoApproveUsers', value)} options={[['manual', 'Manual review'], ['email_verified', 'Email verified'], ['all', 'All users']]} saved={savedSettingField === 'features.moderation.autoApproveUsers'} />
            <EditableSelect label="Default Content Visibility" value={moderationSettings.defaultVisibility || 'public'} onChange={(value) => setFeatureValue('moderation', 'defaultVisibility', value)} options={[['public', 'Public'], ['unlisted', 'Unlisted'], ['private', 'Private']]} saved={savedSettingField === 'features.moderation.defaultVisibility'} />
            <EditableSelect label="Upload Approval" value={moderationSettings.uploadApproval || 'automatic'} onChange={(value) => setFeatureValue('moderation', 'uploadApproval', value)} options={[['automatic', 'Approve automatically'], ['manual', 'Require moderator review']]} saved={savedSettingField === 'features.moderation.uploadApproval'} />
            <EditableToggleField label="Profanity Filter" checked={moderationSettings.profanityFilter ?? true} onChange={(value) => setFeatureValue('moderation', 'profanityFilter', value)} saved={savedSettingField === 'features.moderation.profanityFilter'} />
            <button type="button" className="admin-outline-action primary-text" onClick={saveFeatureSettings} disabled={featureSaving}><AdminIcon name="shield" size={16} />{featureSaving ? 'Saving...' : 'Save Moderation'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-integrations')} title="Integrations" description="Manage third-party services and API integrations.">
            <div className="admin-integration-tabs">
              {integrations.map((integration) => <IntegrationBadge key={integration.name} icon={integration.icon} name={integration.name} status={integration.status} active={activeIntegration === integration.name} onClick={() => setActiveIntegration(integration.name)} />)}
            </div>
            {activeIntegration === 'Google Analytics' && <div className="admin-settings-ad-block">
              <EditableToggleField label="Google Analytics" checked={googleAnalyticsForm.enabled ?? false} onChange={(value) => setIntegrationValue('googleAnalytics', 'enabled', value)} saved={savedSettingField === 'integration.googleAnalytics.enabled'} />
              <div className="admin-settings-field-grid">
                <EditableField label="Google Measurement ID" value={googleAnalyticsForm.measurementId || ''} onChange={(value) => setIntegrationValue('googleAnalytics', 'measurementId', value)} saved={savedSettingField === 'integration.googleAnalytics.measurementId'} wide />
              </div>
            </div>}
            {activeIntegration === 'Stripe' && <div className="admin-settings-ad-block">
              <EditableToggleField label="Stripe Payments" checked={stripeForm.enabled ?? false} onChange={(value) => setIntegrationValue('stripe', 'enabled', value)} saved={savedSettingField === 'integration.stripe.enabled'} />
              <div className="admin-settings-field-grid">
                <EditableField label="Stripe Publishable Key" value={stripeForm.publishableKey || ''} onChange={(value) => setIntegrationValue('stripe', 'publishableKey', value)} saved={savedSettingField === 'integration.stripe.publishableKey'} />
                <EditableField label="Stripe Secret Key" value={stripeForm.secretKey || ''} onChange={(value) => setIntegrationValue('stripe', 'secretKey', value)} saved={savedSettingField === 'integration.stripe.secretKey'} type="password" />
              </div>
            </div>}
            {activeIntegration === 'PayPal' && <div className="admin-settings-ad-block">
              <EditableToggleField label="PayPal Payments" checked={paypalForm.enabled ?? false} onChange={(value) => setIntegrationValue('paypal', 'enabled', value)} saved={savedSettingField === 'integration.paypal.enabled'} />
              <div className="admin-settings-field-grid">
                <EditableSelect label="PayPal Environment" value={paypalForm.environment || 'sandbox'} onChange={(value) => setIntegrationValue('paypal', 'environment', value)} options={[['sandbox', 'Sandbox'], ['live', 'Live']]} saved={savedSettingField === 'integration.paypal.environment'} />
                <EditableField label="PayPal Client ID" value={paypalForm.clientId || ''} onChange={(value) => setIntegrationValue('paypal', 'clientId', value)} saved={savedSettingField === 'integration.paypal.clientId'} />
                <EditableField label="PayPal Client Secret" value={paypalForm.clientSecret || ''} onChange={(value) => setIntegrationValue('paypal', 'clientSecret', value)} saved={savedSettingField === 'integration.paypal.clientSecret'} type="password" wide />
              </div>
            </div>}
            {activeIntegration === 'SendGrid' && <div className="admin-settings-ad-block">
              <EditableToggleField label="SendGrid Email" checked={sendGridForm.enabled ?? false} onChange={(value) => setIntegrationValue('sendgrid', 'enabled', value)} saved={savedSettingField === 'integration.sendgrid.enabled'} />
              <div className="admin-settings-field-grid">
                <EditableField label="SendGrid API Key" value={sendGridForm.apiKey || ''} onChange={(value) => setIntegrationValue('sendgrid', 'apiKey', value)} saved={savedSettingField === 'integration.sendgrid.apiKey'} type="password" wide />
                <EditableField label="SendGrid From Email" value={sendGridForm.fromEmail || ''} onChange={(value) => setIntegrationValue('sendgrid', 'fromEmail', value)} saved={savedSettingField === 'integration.sendgrid.fromEmail'} />
                <EditableField label="SendGrid From Name" value={sendGridForm.fromName || ''} onChange={(value) => setIntegrationValue('sendgrid', 'fromName', value)} saved={savedSettingField === 'integration.sendgrid.fromName'} />
                <EditableField label="Support notification email" value={sendGridForm.notificationsEmail || ''} onChange={(value) => setIntegrationValue('sendgrid', 'notificationsEmail', value)} saved={savedSettingField === 'integration.sendgrid.notificationsEmail'} />
                <button type="button" className="admin-outline-action" onClick={testSendGridSettings} disabled={sendGridTesting}><AdminIcon name="mail" size={16} />{sendGridTesting ? 'Sending...' : 'Test SendGrid'}</button>
              </div>
            </div>}
            <button type="button" className="admin-outline-action primary-text" onClick={saveIntegrationSettings} disabled={integrationSaving}><AdminIcon name="cloud" size={16} />{integrationSaving ? 'Saving...' : 'Save Integrations'}</button>
          </SettingsRow>
          <SettingsRow {...settingsRowProps('setting-maintenance')} title="Maintenance Mode" description="Temporarily disable platform access for maintenance.">
            <EditableToggleField label="Maintenance Mode" checked={maintenanceSettings.enabled ?? false} onChange={(value) => setFeatureValue('maintenance', 'enabled', value)} saved={savedSettingField === 'features.maintenance.enabled'} />
            <EditableTextarea label="Message" value={maintenanceSettings.message || ''} onChange={(value) => setFeatureValue('maintenance', 'message', value)} saved={savedSettingField === 'features.maintenance.message'} placeholder="We'll be back soon." />
            <EditableTextarea label="Allowed IPs (optional)" value={maintenanceSettings.allowedIps || ''} onChange={(value) => setFeatureValue('maintenance', 'allowedIps', value)} saved={savedSettingField === 'features.maintenance.allowedIps'} placeholder="203.0.113.44&#10;198.51.100.*" />
            <button type="button" className="admin-outline-action primary-text" onClick={saveFeatureSettings} disabled={featureSaving}><AdminIcon name="cloud" size={16} />{featureSaving ? 'Saving...' : 'Save Maintenance Settings'}</button>
          </SettingsRow>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Server Status"><ServerStatus rows={data.serverStatusRows} status={data.serverStatus} onStatusPage={() => window.open('/api/health', '_blank', 'noopener')} /></SideCard>
          <SideCard title="Recent Changes" action="Refresh" onAction={onRefresh}><ActivityList rows={data.recentChanges || []} /></SideCard>
          <SideCard title="Backup Summary"><KeyValueIconList rows={data.backupRows || [[ 'Last Backup', 'Not configured'], ['Next Backup', 'Not scheduled'], ['Backup Size', '0 B']]} /><button type="button" className="admin-full-outline" onClick={() => runQuickAction('Run Backup Now')}><AdminIcon name="cloud" size={16} />Run Backup Now</button></SideCard>
          <QuickActionList actions={['Clear Cache', 'View System Logs', 'Manage Cron Jobs']} onAction={runQuickAction} />
        </aside>
      </div>
      {backupDialogOpen && <AdminBackupDialog api={api} alertAction={alertAction} onRefresh={onRefresh} onClose={() => setBackupDialogOpen(false)} />}

    </>
  );
}

function LiveStreamsPage({ navigate, data = {}, api = null, onRefresh = () => {}, confirmAction = null, alertAction = null }) {
  const fallbackRows = data.rows || [];
  const tabs = data.tabs || [['Live Now', '0'], ['Upcoming', '0'], ['Ended', '0']];
  const [activeTab, setActiveTab] = useState(tabs[0]?.[0] || 'Live Now');
  const [tableSearch, setTableSearch] = useState('');
  const [tablePage, setTablePage] = useState(1);
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [healthFilter, setHealthFilter] = useState('All');
  const [tableData, setTableData] = useState(null);
  const [tableLoading, setTableLoading] = useState(false);
  const [tableError, setTableError] = useState('');
  const [tableReload, setTableReload] = useState(0);
  useEffect(() => setTablePage(1), [activeTab, tableSearch, categoryFilter, healthFilter]);
  useEffect(() => {
    if (!api?.request) return undefined;
    let cancelled = false;
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({ page: String(tablePage), per_page: '25' });
      const status = { 'Live Now': 'live', Upcoming: 'scheduled', Ended: 'ended' }[activeTab];
      if (status) params.set('status', status);
      if (tableSearch.trim()) params.set('search', tableSearch.trim());
      if (categoryFilter !== 'All') params.set('category', categoryFilter);
      if (healthFilter !== 'All') params.set('health', healthFilter);
      setTableLoading(true);
      setTableError('');
      api.request(`/admin/live-streams?${params.toString()}`)
        .then((payload) => { if (!cancelled) setTableData(payload); })
        .catch((error) => { if (!cancelled) setTableError(error.message || 'Unable to load live streams.'); })
        .finally(() => { if (!cancelled) setTableLoading(false); });
    }, tableSearch.trim() ? 180 : 0);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [api, activeTab, tableSearch, categoryFilter, healthFilter, tablePage, tableReload]);
  const rows = tableData?.rows || fallbackRows;
  const tablePagination = {
    rows,
    from: tableData?.from ?? (rows.length ? 1 : 0),
    to: tableData?.to ?? rows.length,
    total: tableData?.total ?? rows.length,
    activePage: tableData?.page || tablePage,
    lastPage: tableData?.last_page || 1,
    pages: adminPaginationPages(tableData?.page || tablePage, tableData?.last_page || 1),
  };
  const refreshLiveStreams = () => { setTableReload((value) => value + 1); onRefresh?.(); };
  const resetLiveFilters = () => { setTableSearch(''); setCategoryFilter('All'); setHealthFilter('All'); setActiveTab(tabs[0]?.[0] || 'Live Now'); };
  const categoryOptions = ['All', ...(data.categories || [])];
  const scheduleRows = data.scheduleRows || [];
  const healthItems = Array.isArray(data.healthItems) ? data.healthItems : [['Excellent', '0 (0%)', '#76c943'], ['Good', '0 (0%)', '#84c341'], ['Fair', '0 (0%)', '#f8b62d'], ['Poor', '0 (0%)', '#ef4444']];
  const topCategories = data.topCategories || [];
  const topCategoryMax = Math.max(...topCategories.map((row) => parseFloat(String(row.value).replace(/[^0-9.]/g, '')) || 0), 1);
  const runQuickAction = (action) => runAdminQuickAction(action, { navigate, api, data, alertAction });

  return (
    <>
      <MetricGrid metrics={data.metrics || []} columns={4} />
      <div className="admin-content-grid live-grid">
        <div className="admin-main-stack">
          <TablePanel flush>
            <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} />
            <FilterBar search="Search by stream title or streamer..." value={tableSearch} onSearch={setTableSearch} selects={[{ label: 'Status', value: activeTab, options: tabs.map(([label]) => label), onChange: setActiveTab }, { label: 'Category', value: categoryFilter, options: categoryOptions, onChange: setCategoryFilter }, { label: 'Health', value: healthFilter, options: ['All', 'Ready', 'Needs Attention', 'Not Tested'], onChange: setHealthFilter }]} refresh reset onRefresh={refreshLiveStreams} onReset={resetLiveFilters} />
            {tableError && <div className="admin-table-error" role="alert">{tableError}</div>}
            {tableLoading && !rows.length ? <div className="admin-table-loading">Loading live streams...</div> : <LiveTable rows={tablePagination.rows} navigate={navigate} api={api} onRefresh={refreshLiveStreams} confirmAction={confirmAction} alertAction={alertAction} />}
            <TableFooter text={`Showing ${tablePagination.from} to ${tablePagination.to} of ${tablePagination.total} live streams`} pages={tablePagination.pages} activePage={tablePagination.activePage} lastPage={tablePagination.lastPage} onPageChange={setTablePage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Today's Live Schedule" action="View all" onAction={() => navigate('/admin/live-streams')}><ScheduleList rows={scheduleRows} /></SideCard>
          <SideCard title="Stream Health Overview" action="View all" onAction={() => navigate('/admin/live-streams')}><DonutMini items={healthItems} center={data.healthCenter || tabs[0]?.[1] || '0'} sub="Total" /></SideCard>
          <SideCard title="Top Live Categories" action="View all" onAction={() => navigate('/admin/live-streams')}><ProgressList rows={topCategories} max={topCategoryMax} compact /></SideCard>
          <QuickActionList actions={['Create Live Event', 'Schedule Live Stream', 'Moderation Queue', 'Flagged Streams']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

function ReportsPage({ navigate, data = {}, api = null, onRefresh = () => {}, confirmAction = null, alertAction = null, routeSearch = '', canManageAdmin = true }) {
  const rows = data.rows || [];
  const tabs = data.tabs || [['All Reports', '0'], ['Content', '0'], ['Users', '0'], ['Live', '0'], ['Copyright', '0'], ['Appeals', '0']];
  const [activeTab, setActiveTab] = useState(tabs[0]?.[0] || 'All Reports');
  const routeSearchTerm = adminRouteParam(routeSearch, 'search');
  const [tableSearch, setTableSearch] = useState(routeSearchTerm);
  const [tablePage, setTablePage] = useState(1);
  const [typeFilter, setTypeFilter] = useState('All');
  const [priorityFilter, setPriorityFilter] = useState('All');
  const [statusFilter, setStatusFilter] = useState('All');
  const [moderatorFilter, setModeratorFilter] = useState('All');
  useEffect(() => setTableSearch(routeSearchTerm), [routeSearchTerm]);
  useEffect(() => setTablePage(1), [activeTab, tableSearch, typeFilter, priorityFilter, statusFilter, moderatorFilter]);
  const highlightId = useAdminRouteTarget(routeSearch, rows.length);
  const searchedRows = filterAdminRows(rows, tableSearch, (row) => [row.type, row.title, row.byline, row.reporter, row.reason, row.priority, row.status]);
  const filteredRows = searchedRows.filter((row) => (activeTab === 'All Reports' || (activeTab === 'Content' && row.type !== 'Profile') || (activeTab === 'Users' && row.type === 'Profile'))
    && (typeFilter === 'All' || row.type === typeFilter)
    && (priorityFilter === 'All' || row.priority === priorityFilter)
    && (statusFilter === 'All' || row.status === statusFilter)
    && (moderatorFilter === 'All' || (row.moderator?.name || 'Unassigned') === moderatorFilter));
  const tablePagination = paginateAdminRows(filteredRows, tablePage);
  const categoryRows = data.categoryRows || data.reportCategoryRows || [];
  const moderators = data.moderatorsOnline || [];
  const resolutionItems = data.resolutionItems || [['Resolved', '0 (0%)', '#6b2fff'], ['Open', '0 (0%)', '#ff8a00']];
  const typeOptions = ['All', ...Array.from(new Set(rows.map((row) => row.type).filter(Boolean)))];
  const priorityOptions = ['All', ...Array.from(new Set(rows.map((row) => row.priority).filter(Boolean)))];
  const statusOptions = ['All', ...Array.from(new Set(rows.map((row) => row.status).filter(Boolean)))];
  const moderatorOptions = ['All', ...Array.from(new Set(rows.map((row) => row.moderator?.name || 'Unassigned').filter(Boolean)))];
  const resetFilters = () => { setTableSearch(''); setTypeFilter('All'); setPriorityFilter('All'); setStatusFilter('All'); setModeratorFilter('All'); };
  const runQuickAction = async (action) => {
    if (action === 'Export Reports') {
      if (filteredRows.length) downloadRowsCsv(filteredRows, 'playhub-reports.csv');
      else await alertAction?.({ title: 'No report data', message: 'There are no matching reports to export.', tone: 'danger' });
      return;
    }
    if (action === 'Report Settings') { navigate('/admin/system-settings?highlight=setting-moderation#setting-moderation'); return; }
    if (action === 'View Report Guidelines') {
      await alertAction?.({ title: 'Report review guidelines', message: 'Review the reported item and its context. Dismiss reports that do not violate policy. Delete content only when it violates platform rules, and use user restrictions for repeated or severe abuse.' });
      return;
    }
    if (action === 'Bulk Resolve Reports') {
      if (!filteredRows.length) { await alertAction?.({ title: 'No reports selected', message: 'There are no matching reports to resolve.', tone: 'danger' }); return; }
      const confirmed = await confirmAction?.({ title: 'Resolve matching reports?', message: `Dismiss reports for ${filteredRows.length} visible reported item${filteredRows.length === 1 ? '' : 's'}?`, confirmLabel: 'Resolve reports' });
      if (!confirmed) return;
      const targets = Array.from(new Map(filteredRows.map((row) => [`${row.type}-${row.video_id || row.comment_id || row.user_id}`, row])).values());
      try {
        await Promise.all(targets.map((row) => api.request(`${adminReportPath(row)}/dismiss`, { method: 'POST' })));
        onRefresh?.();
        await alertAction?.({ title: 'Reports resolved', message: `${targets.length} reported item${targets.length === 1 ? '' : 's'} cleared from the queue.` });
      } catch (error) {
        await alertAction?.({ title: 'Bulk action failed', message: error.message || 'The reports could not be resolved.', tone: 'danger' });
      }
    }
  };

  return (
    <>
      <MetricGrid metrics={data.metrics || []} columns={4} />
      <div className="admin-content-grid reports-grid">
        <div className="admin-main-stack">
          <TablePanel flush>
            <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} />
            <FilterBar search="Search reports..." value={tableSearch} onSearch={setTableSearch} selects={[{ label: 'Report Type', value: typeFilter, options: typeOptions, onChange: setTypeFilter }, { label: 'Priority', value: priorityFilter, options: priorityOptions, onChange: setPriorityFilter }, { label: 'Status', value: statusFilter, options: statusOptions, onChange: setStatusFilter }, { label: 'Moderator', value: moderatorFilter, options: moderatorOptions, onChange: setModeratorFilter }]} reset onReset={resetFilters} />
            <ReportsTable rows={tablePagination.rows} navigate={navigate} api={api} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} highlightId={highlightId} />
            <TableFooter text={`Showing ${tablePagination.from} to ${tablePagination.to} of ${tablePagination.total} reports`} pages={tablePagination.pages} activePage={tablePagination.activePage} lastPage={tablePagination.lastPage} onPageChange={setTablePage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Report Categories" action="Clear filters" onAction={resetFilters}><LegendList rows={categoryRows} /></SideCard>
          <SideCard title="Moderators Online" action="View all" onAction={() => navigate('/admin/roles-permissions')}><PeopleOnline rows={moderators} /></SideCard>
          <SideCard title="Resolution Rate" action="Review queue" onAction={() => { setActiveTab('All Reports'); setStatusFilter('All'); }}><DonutMini items={resolutionItems} center={data.resolutionRate || '0%'} sub="This Week" /><TrendText>{data.resolutionChange || '0% vs last week'}</TrendText></SideCard>
          <QuickActionList actions={canManageAdmin ? ['Bulk Resolve Reports', 'Export Reports', 'Report Settings', 'View Report Guidelines'] : ['Export Reports', 'View Report Guidelines']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

function MonetizationPage({ navigate, data = {}, api = null, onRefresh = null, confirmAction = null, alertAction = null }) {
  const revenueChart = data.revenueChart || { title: 'Revenue Overview', subtitle: '', yLabels: ['$100K', '$80K', '$60K', '$40K', '$20K', '$0'], labels: [], series: [{ points: zeroTrend, color: 'primary' }], legends: [['Total Revenue', 'primary'], ['Ad Revenue', 'blue'], ['Membership Revenue', 'green'], ['Tips Revenue', 'orange']], action: 'Last 28 days' };
  const revenueSources = data.revenueSources ? data.revenueSources.map(([label, value, color]) => ({ label, value: String(value), color })) : [];
  const settingsRef = useRef(null);
  const defaultSettings = { enabled: false, currency: 'usd', vast_ads: { creator_share_percent: 70 }, paid_videos: { enabled: true, commission_percent: 10, minimum_price: '0.99', maximum_price: '1000.00', sales_tax: { enabled: false, type: 'vat', rate_percent: 0, registration_number: '' } }, video_boosts: { enabled: true, minimum_bid: '0.01', maximum_bid: '100.00', minimum_budget: '5.00', maximum_budget: '100000.00', max_active_campaigns: 10 } };
  const normalizeSettings = (value = {}) => ({ ...defaultSettings, ...value, vast_ads: { ...defaultSettings.vast_ads, ...(value.vast_ads || {}) }, paid_videos: { ...defaultSettings.paid_videos, ...(value.paid_videos || {}), sales_tax: { ...defaultSettings.paid_videos.sales_tax, ...(value.paid_videos?.sales_tax || {}) } }, video_boosts: { ...defaultSettings.video_boosts, ...(value.video_boosts || {}) } });
  const [settings, setSettings] = useState(() => normalizeSettings(data.settings));
  const [settingsSaving, setSettingsSaving] = useState(false);
  const [payoutRows, setPayoutRows] = useState(() => data.payoutRows || []);
  const [payoutBusy, setPayoutBusy] = useState('');
  const [payoutPage, setPayoutPage] = useState(1);
  useEffect(() => setSettings(normalizeSettings(data.settings)), [data.settings]);
  useEffect(() => setPayoutRows(data.payoutRows || []), [data.payoutRows]);
  useEffect(() => setPayoutPage(1), [payoutRows.length]);
  const payoutPagination = paginateAdminRows(payoutRows, payoutPage);
  const saveSettings = async (event) => {
    event.preventDefault();
    if (!api?.request || settingsSaving) return;
    setSettingsSaving(true);
    try {
      const response = await api.request('/admin/monetization-settings', { method: 'PUT', body: JSON.stringify(settings) });
      setSettings((current) => ({ ...current, ...(response.settings || {}) }));
      await alertAction?.({ title: 'Monetization saved', message: response.message || 'Monetization settings saved.' });
    } catch (error) {
      await alertAction?.({ title: 'Save failed', message: error.message || 'Monetization settings could not be saved.', tone: 'danger' });
    } finally {
      setSettingsSaving(false);
    }
  };
  const runQuickAction = (action) => {
    if (action === 'Revenue Settings') {
      settingsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    if (action === 'Export Transactions') {
      if ((data.transactions || []).length) downloadRowsCsv(data.transactions, 'playhub-transactions.csv');
      else alertAction?.({ title: 'No export data', message: 'There are no transactions available for this export.', tone: 'danger' });
      return;
    }
    return runAdminQuickAction(action, { navigate, api, data, alertAction });
  };
  const decidePayout = async (row, decision) => {
    if (!api?.request || !row?.id || payoutBusy) return;
    const approving = decision === 'approved';
    const confirmed = await confirmAction?.({
      title: approving ? 'Approve withdrawal' : 'Reject withdrawal',
      message: approving
        ? `Confirm that ${row.amount} has been paid to ${row.account || row.method}. This marks the request complete and cannot be reversed.`
        : `Reject ${row.amount} for ${row.person?.name || 'this creator'}? The reserved funds will return to their available wallet balance.`,
      confirmLabel: approving ? 'Approve withdrawal' : 'Reject withdrawal',
      tone: approving ? 'primary' : 'danger',
    });
    if (!confirmed) return;

    setPayoutBusy(`${row.id}:${decision}`);
    try {
      const response = await api.request(`/admin/withdrawals/${row.id}/decision`, { method: 'POST', body: JSON.stringify({ decision }) });
      setPayoutRows((current) => current.map((item) => Number(item.id) === Number(row.id) ? response.payout : item));
      await alertAction?.({ title: approving ? 'Withdrawal approved' : 'Withdrawal rejected', message: response.message });
      onRefresh?.();
    } catch (error) {
      await alertAction?.({ title: 'Withdrawal decision failed', message: error.message || 'The withdrawal request could not be updated.', tone: 'danger' });
    } finally {
      setPayoutBusy('');
    }
  };

  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid monetization-grid">
        <div className="admin-main-stack">
          <div className="admin-revenue-row">
            <ChartPanel {...revenueChart} />
            <DonutPanel title="Revenue by Source" items={revenueSources} center={data.revenueTotal || revenueChart.value || '$0'} sub="Total" />
          </div>
          <TablePanel title="Monetization Settings" action="Payment settings" onAction={() => runQuickAction('Payment settings')}>
            <form ref={settingsRef} className="admin-settings-controls monetization-settings-form" onSubmit={saveSettings}>
              <EditableToggleField label="Enable creator monetization" checked={Boolean(settings.enabled)} onChange={(value) => setSettings((current) => ({ ...current, enabled: value }))} />
              <div className="admin-settings-subheading"><strong>VAST ad revenue</strong><span>Only completed VAST ads on monetized videos generate creator earnings.</span></div>
              <EditableField label="Creator VAST revenue share" type="number" step="0.01" suffix="%" value={settings.vast_ads?.creator_share_percent ?? 70} onChange={(value) => setSettings((current) => ({ ...current, vast_ads: { ...current.vast_ads, creator_share_percent: value } }))} />
              <EditableToggleField label="Enable paid video and photo sales" checked={Boolean(settings.paid_videos?.enabled)} onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, enabled: value } }))} />
              <EditableField label="Paid content commission" type="number" step="0.01" suffix="%" value={settings.paid_videos?.commission_percent ?? 10} onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, commission_percent: value } }))} />
              <EditableField label={`Minimum content price (${String(settings.currency || 'usd').toUpperCase()})`} type="number" step="0.01" value={settings.paid_videos?.minimum_price ?? '0.99'} onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, minimum_price: value } }))} />
              <EditableField label={`Maximum content price (${String(settings.currency || 'usd').toUpperCase()})`} type="number" step="0.01" value={settings.paid_videos?.maximum_price ?? '1000.00'} onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, maximum_price: value } }))} />
              <EditableToggleField label="Charge sales tax on paid content" checked={Boolean(settings.paid_videos?.sales_tax?.enabled)} onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, sales_tax: { ...current.paid_videos?.sales_tax, enabled: value } } }))} />
              {settings.paid_videos?.sales_tax?.enabled && <>
                <EditableSelect label="Tax type" value={settings.paid_videos.sales_tax.type || 'vat'} onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, sales_tax: { ...current.paid_videos?.sales_tax, type: value } } }))} options={[["vat", "VAT"], ["gst", "GST"], ["sales_tax", "Sales Tax"]]} />
                <EditableField label="Tax rate" type="number" step="0.01" suffix="%" value={settings.paid_videos.sales_tax.rate_percent ?? 0} onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, sales_tax: { ...current.paid_videos?.sales_tax, rate_percent: value } } }))} />
                <EditableField label="Tax registration number" value={settings.paid_videos.sales_tax.registration_number || ''} placeholder="Optional" onChange={(value) => setSettings((current) => ({ ...current, paid_videos: { ...current.paid_videos, sales_tax: { ...current.paid_videos?.sales_tax, registration_number: value } } }))} wide />
              </>}
              <div className="admin-settings-subheading"><strong>Video boosts</strong><span>Paid placement in Home recommendations and Trending.</span></div>
              <EditableToggleField label="Enable video boosts" checked={Boolean(settings.video_boosts?.enabled)} onChange={(value) => setSettings((current) => ({ ...current, video_boosts: { ...current.video_boosts, enabled: value } }))} />
              <EditableField label={`Minimum bid (${String(settings.currency || 'usd').toUpperCase()})`} type="number" step="0.01" value={settings.video_boosts?.minimum_bid ?? '0.01'} onChange={(value) => setSettings((current) => ({ ...current, video_boosts: { ...current.video_boosts, minimum_bid: value } }))} />
              <EditableField label={`Maximum bid (${String(settings.currency || 'usd').toUpperCase()})`} type="number" step="0.01" value={settings.video_boosts?.maximum_bid ?? '100.00'} onChange={(value) => setSettings((current) => ({ ...current, video_boosts: { ...current.video_boosts, maximum_bid: value } }))} />
              <EditableField label={`Minimum campaign budget (${String(settings.currency || 'usd').toUpperCase()})`} type="number" step="0.01" value={settings.video_boosts?.minimum_budget ?? '5.00'} onChange={(value) => setSettings((current) => ({ ...current, video_boosts: { ...current.video_boosts, minimum_budget: value } }))} />
              <EditableField label={`Maximum campaign budget (${String(settings.currency || 'usd').toUpperCase()})`} type="number" step="0.01" value={settings.video_boosts?.maximum_budget ?? '100000.00'} onChange={(value) => setSettings((current) => ({ ...current, video_boosts: { ...current.video_boosts, maximum_budget: value } }))} />
              <EditableField label="Active campaigns per creator" type="number" step="1" value={settings.video_boosts?.max_active_campaigns ?? 10} onChange={(value) => setSettings((current) => ({ ...current, video_boosts: { ...current.video_boosts, max_active_campaigns: value } }))} />
              <div className="admin-settings-actions">
                <button type="submit" className="admin-action-button primary" disabled={settingsSaving}>{settingsSaving ? 'Saving...' : 'Save monetization'}</button>
              </div>
            </form>
          </TablePanel>
          <TablePanel title="Payout Requests" action="Payment settings" onAction={() => runQuickAction('Payment settings')}>
            <PayoutTable rows={payoutPagination.rows} navigate={navigate} onDecision={decidePayout} busy={payoutBusy} />
            <TableFooter text={`Showing ${payoutPagination.from} to ${payoutPagination.to} of ${payoutPagination.total} requests`} pages={payoutPagination.pages} activePage={payoutPagination.activePage} lastPage={payoutPagination.lastPage} onPageChange={setPayoutPage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Top Earning Creators" action="View all" onAction={() => navigate('/admin/creators')}><RankedPeople rows={data.topEarningCreators || []} numbered /></SideCard>
          <SideCard title="Recent Transactions" action="Export" onAction={() => runQuickAction('Export Transactions')}><TransactionList rows={data.transactions || []} /></SideCard>
          <QuickActionList actions={['Payment Settings', 'Export Transactions', 'Revenue Settings']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

function parseEmailList(value) {
  return String(value || '').split(/[,\s]+/).map((item) => item.trim().toLowerCase()).filter(Boolean);
}

function parseMassImportUrls(value) {
  return String(value || '').split(/[\s,]+/).map((item) => item.trim()).filter((item) => /^https?:\/\//i.test(item));
}

async function adminJsonAction(api, path, options = {}, onRefresh = null, alertAction = null) {
  if (!api?.request) {
    await alertAction?.({ title: 'Admin API unavailable', message: 'Admin API client is unavailable.', tone: 'danger' });
    return null;
  }

  try {
    const data = await api.request(path, options);
    if (data?.message) await alertAction?.({ title: 'Action completed', message: data.message });
    onRefresh?.();
    return data;
  } catch (error) {
    await alertAction?.({ title: 'Action failed', message: error.message || 'Admin action failed.', tone: 'danger' });
    return null;
  }
}

async function downloadAdminFile(api, path, filename, alertAction = null) {
  try {
    const headers = new Headers({ Accept: 'text/csv' });
    const token = api?.token?.();
    if (token) headers.set('Authorization', `Bearer ${token}`);
    const response = await fetch(`/api${path}`, { headers });
    if (!response.ok) throw new Error(await response.text() || 'Download failed.');
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    await alertAction?.({ title: 'Download failed', message: error.message || 'Download failed.', tone: 'danger' });
  }
}

async function downloadBackupArchive(api, backup, alertAction = null) {
  try {
    const fileHandle = typeof window.showSaveFilePicker === 'function'
      ? await window.showSaveFilePicker({ suggestedName: backup.archive || 'playhub-backup.zip', types: [{ description: 'ZIP archive', accept: { 'application/zip': ['.zip'] } }] })
      : null;
    const headers = new Headers({ Accept: 'application/zip' });
    const token = api?.token?.();
    if (token) headers.set('Authorization', `Bearer ${token}`);
    const response = await fetch(`/api${backup.download_url}`, { headers });
    if (!response.ok || !response.body) throw new Error(await response.text() || 'Download failed.');

    if (fileHandle) {
      const writable = await fileHandle.createWritable();
      await response.body.pipeTo(writable);
      return;
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = backup.archive || 'playhub-backup.zip';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    if (error?.name === 'AbortError') return;
    await alertAction?.({ title: 'Download failed', message: error.message || 'Download failed.', tone: 'danger' });
  }
}

function downloadRowsCsv(rows = [], filename = 'admin-export.csv') {
  const flatten = (value) => {
    if (value == null) return '';
    if (typeof value !== 'object') return String(value);
    if (Array.isArray(value)) return value.map(flatten).join(' ');
    return value.name || value.title || value.email || value.handle || JSON.stringify(value);
  };
  const keys = Array.from(rows.reduce((set, row) => {
    Object.keys(row || {}).filter((key) => !key.startsWith('_')).forEach((key) => set.add(key));
    return set;
  }, new Set()));
  const csv = [keys.join(','), ...rows.map((row) => keys.map((key) => `"${flatten(row?.[key]).replace(/"/g, '""')}"`).join(','))].join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function runAdminPageAction(pageKey, action, { api, navigate, adminData, onRefresh, alertAction = null, promptAction = null, setAdminNotifications = null, setAdminProgress = null }) {
  if (pageKey === 'users') {
    await runUsersPageAction(action, api, onRefresh, alertAction, promptAction);
    return;
  }
  if (pageKey === 'creators') {
    await runCreatorsPageAction(action, api, onRefresh, alertAction, promptAction);
    return;
  }

  if (/^Export/i.test(action)) {
    const page = adminData?.pages?.[pageKey] || {};
    const rows = page.rows || page.transactions || page.payoutRows || page.roleRows || page.teamRows || [];
    if (!rows.length) {
      await alertAction?.({ title: 'No export data', message: 'There are no rows available to export on this page.', tone: 'danger' });
      return;
    }
    downloadRowsCsv(rows, `playhub-${pageKey}.csv`);
    return;
  }

  if (action === 'Upload Content') {
    navigate('/upload');
    return;
  }

  if (action === 'Mass Import') {
    await runContentMassImportAction({ api, adminData, onRefresh, alertAction, promptAction, setAdminNotifications, setAdminProgress });
    return;
  }

  if (action === 'Go Live') {
    navigate('/live');
    return;
  }

  if (action === 'Payout Settings') {
    navigate('/admin/system-settings?section=integrations&highlight=setting-integrations#setting-integrations');
    return;
  }

  if (action === 'Invite Creator' || action === 'Invite Admin') {
    const payload = await promptAction?.({
      type: 'invite',
      title: action,
      description: 'Create an account invite with the selected role.',
      submitLabel: action,
    });
    if (!payload) return;
    const role = action === 'Invite Admin' ? 'admin' : 'creator';
    await adminJsonAction(api, '/admin/users/invite', { method: 'POST', body: JSON.stringify({ ...payload, role }) }, onRefresh, alertAction);
    return;
  }

  if (pageKey === 'reports' && action === 'Bulk Actions') {
    const detail = { count: 0 };
    window.dispatchEvent(new CustomEvent('playhub:reports-bulk-actions', { detail }));
    if (!detail.count) await alertAction?.({ title: 'No reports available', message: 'There are no reports on this page to select.', tone: 'danger' });
    return;
  }
}

async function runContentMassImportAction({ api, adminData, onRefresh, alertAction = null, promptAction = null, setAdminNotifications = null, setAdminProgress = null }) {
  if (!api?.request || !promptAction) {
    await alertAction?.({ title: 'Mass import unavailable', message: 'Admin API client is unavailable.', tone: 'danger' });
    return;
  }

  const options = adminData?.pages?.content?.massImport || {};
  const importSettings = options.video_import || {};
  const providers = (importSettings.providers || []).filter((provider) => provider.enabled && provider.key !== 'direct');

  if (!importSettings.enabled || !providers.length) {
    await alertAction?.({ title: 'Mass import unavailable', message: 'Enable YouTube, Vimeo, Dailymotion, or Twitch in System Settings > Video Import first.', tone: 'danger' });
    return;
  }

  const result = await promptAction?.({
    type: 'mass-import',
    title: 'Mass Import',
    description: 'Paste URLs or leave them empty to import by provider, keyword, or category. Every video is saved with its own request.',
    submitLabel: 'Start Import',
    processingLabel: 'Importing...',
    providers,
    categories: options.categories || [],
    defaultProvider: providers[0]?.key || '',
    onSubmit: (payload) => {
      runContentMassImportBatch(payload, { api, onRefresh, alertAction, setAdminNotifications, setAdminProgress });
    },
    onError: async (error) => {
      await alertAction?.({ title: 'Mass import failed', message: error.message || 'Mass import failed.', tone: 'danger' });
    },
  });
}

async function runContentMassImportBatch(payload, { api, onRefresh, alertAction = null, setAdminNotifications = null, setAdminProgress = null }) {
  const setProgress = (percent, text) => setAdminProgress?.({ label: 'Mass import', percent, text });

  setProgress(0, 'Starting mass import...');
  try {
      let urls = Array.isArray(payload.urls) ? payload.urls : [];
      const targetCount = Math.max(1, Math.min(50, Number(payload.count) || urls.length || 1));
      let skipped = 0;
      if (payload.skip_duplicates) {
        const seen = new Set();
        urls = urls.filter((url) => {
          const key = String(url).toLowerCase();
          if (seen.has(key)) {
            skipped += 1;
            return false;
          }
          seen.add(key);
          return true;
        });
      }
      const discoveryMode = !urls.length;
      if (!discoveryMode && urls.length > targetCount) {
        skipped += urls.length - targetCount;
        urls = urls.slice(0, targetCount);
      }
      if (discoveryMode && !payload.category_id && !payload.keyword) throw new Error('Choose a category, enter a keyword, or paste video URLs.');

      const summary = { provider: payload.provider, total: discoveryMode ? targetCount : urls.length, succeeded: 0, failed: 0, skipped, errors: [] };
      const seenUrls = new Set(urls.map((url) => String(url).toLowerCase()));
      let cursor = '';
      for (let index = 0; index < summary.total; index += 1) {
        const itemNumber = index + 1;
        let url = urls[index] || '';
        setProgress(Math.round((index / summary.total) * 100), discoveryMode ? `Finding video ${itemNumber} of ${summary.total}` : `Importing ${itemNumber} of ${summary.total}`);
        try {
          if (discoveryMode) {
            const discovered = await api.request('/admin/content/mass-import/discover', {
              method: 'POST',
              body: JSON.stringify({
                provider: payload.provider,
                category_id: payload.category_id,
                keyword: payload.keyword || '',
                cursor,
                exclude: Array.from(seenUrls).slice(-100),
              }),
            });
            url = discovered?.url || '';
            cursor = discovered?.cursor || '';
            if (!url) throw new Error('No provider video URL was returned.');
            seenUrls.add(String(url).toLowerCase());
            setProgress(Math.round((index / summary.total) * 100), `Importing ${itemNumber} of ${summary.total}`);
          }
          await api.request('/video-imports', {
            method: 'POST',
            body: JSON.stringify({
              provider: payload.provider,
              url,
              category_id: payload.category_id || null,
              visibility: payload.visibility || 'public',
              tags: payload.tags || '',
              made_for_kids: Boolean(payload.made_for_kids),
              monetization_enabled: Boolean(payload.monetization_enabled),
              mass_import: true,
            }),
          });
          summary.succeeded += 1;
        } catch (error) {
          const message = error.message || 'Import failed.';
          if (discoveryMode && !url && /^No new .* videos were found/i.test(message)) {
            summary.skipped += summary.total - itemNumber + 1;
            break;
          }
          summary.failed += 1;
          summary.errors.push(`${url}: ${message}`);
          if (payload.stop_on_error) {
            summary.skipped += summary.total - itemNumber;
            break;
          }
        }
        setProgress(Math.round((itemNumber / summary.total) * 100), `Imported ${itemNumber} of ${summary.total}`);
      }

      const notice = await api.request('/admin/content/mass-import/complete', {
        method: 'POST',
        body: JSON.stringify({
          provider: payload.provider,
          total: summary.total,
          succeeded: summary.succeeded,
          failed: summary.failed,
          skipped: summary.skipped,
          errors: summary.errors.slice(0, 5),
        }),
      });
      if (notice?.notifications) setAdminNotifications?.(notice.notifications);
      onRefresh?.();

      setProgress(100, 'Mass import completed');
      const parts = [`${summary.succeeded} imported`, `${summary.failed} failed`];
      if (summary.skipped) parts.push(`${summary.skipped} skipped`);
      const errors = summary.errors?.length ? `\n\n${summary.errors.slice(0, 5).join('\n')}` : '';
      await alertAction?.({ title: 'Mass import finished', message: `${parts.join(', ')}.${errors}`, tone: summary.failed ? 'danger' : 'primary' });
      window.setTimeout(() => setAdminProgress?.(null), 4500);
  } catch (error) {
      setProgress(100, 'Mass import failed');
      await alertAction?.({ title: 'Mass import failed', message: error.message || 'Mass import failed.', tone: 'danger' });
      window.setTimeout(() => setAdminProgress?.(null), 6500);
  }
}

async function runAdminQuickAction(action, { navigate, api, data = {}, alertAction = null, promptAction = null, onRefresh = null, contentFilters = null }) {
  if (action === 'View Analytics') {
    navigate('/admin/analytics');
    return;
  }
  if (/^Payment Settings$/i.test(action)) {
    navigate('/admin/system-settings?section=integrations&highlight=setting-integrations#setting-integrations');
    return;
  }
  if (action === 'Report Settings') {
    navigate('/admin/system-settings?section=moderation&highlight=setting-moderation#setting-moderation');
    return;
  }
  if (action === 'Review Flagged Content') {
    navigate('/admin/reports');
    return;
  }
  if (action === 'Schedule Content') {
    navigate('/admin/content?status=Scheduled');
    return;
  }
  if (action === 'Bulk Update Categories') {
    const categories = data.massImport?.categories || [];
    if (!promptAction) {
      await alertAction?.({ title: 'Bulk Update Categories', message: 'The category update dialog is unavailable.', tone: 'danger' });
      return;
    }
    const payload = await promptAction({
      type: 'bulk-category',
      title: 'Bulk Update Categories',
      description: 'Choose the category to apply to content matching the current filters.',
      categories,
      scopeLabel: contentFilterSummary(contentFilters),
      submitLabel: 'Update Categories',
    });
    if (payload) {
      await adminJsonAction(api, '/admin/content/videos/bulk-category', { method: 'POST', body: JSON.stringify({ ...(contentFilters || {}), all: true, category_id: payload.category_id }) }, onRefresh, alertAction);
    }
    return;
  }
  if (action === 'Manage Content Categories') {
    const categories = data.massImport?.categories || [];
    if (!promptAction) {
      await alertAction?.({ title: 'Manage Content Categories', message: 'The category management dialog is unavailable.', tone: 'danger' });
      return;
    }
    const payload = await promptAction({
      type: 'manage-category',
      title: 'Manage Content Categories',
      description: 'Add, edit or delete upload and content categories. Deleting a category keeps its videos as Uncategorized.',
      categories,
      submitLabel: 'Create Category',
    });
    if (payload?.action === 'delete') {
      if (!payload.id) return;
      await adminJsonAction(api, `/admin/content/categories/${encodeURIComponent(payload.id)}`, { method: 'DELETE' }, onRefresh, alertAction);
    } else if (payload) {
      const categoryPath = payload.id ? `/admin/content/categories/${payload.id}` : '/admin/content/categories';
      await adminJsonAction(api, categoryPath, { method: payload.id ? 'PUT' : 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    }
    return;
  }
  if (/export|download/i.test(action)) {
    const rows = data.rows || data.transactions || data.payoutRows || data.roleRows || [];
    if (rows.length) downloadRowsCsv(rows, `playhub-${action.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`);
    else await alertAction?.({ title: 'No export data', message: 'There are no rows available for this export.', tone: 'danger' });
    return;
  }
  if (/report|flag|moderation|guideline/i.test(action)) {
    navigate('/admin/reports');
    return;
  }
  if (/role|permission|matrix|security/i.test(action)) {
    navigate(/security/i.test(action) ? '/admin/system-settings?section=login&highlight=setting-login#setting-login' : '/admin/roles-permissions');
    return;
  }
  if (/setting|method|revenue|category|cron/i.test(action)) {
    navigate('/admin/system-settings');
    return;
  }
  if (/create live|schedule live/i.test(action)) {
    navigate('/go-live');
    return;
  }
  if (/schedule content|upload/i.test(action)) {
    navigate('/upload');
    return;
  }
  if (/ticket|sla|response/i.test(action)) {
    navigate('/admin/support');
    return;
  }
  if (/invite|verify|creator|user/i.test(action)) {
    navigate('/admin/users');
    return;
  }
  if (/announcement/i.test(action)) {
    navigate('/messages');
    return;
  }
  if (/payout|payment|tax/i.test(action)) {
    navigate('/admin/system-settings?section=integrations&highlight=setting-integrations#setting-integrations');
    return;
  }
  if (/clear cache/i.test(action)) {
    await adminJsonAction(api, '/admin/system-actions', { method: 'POST', body: JSON.stringify({ action: 'clear_cache' }) }, null, alertAction);
    return;
  }
  if (/rebuild search/i.test(action)) {
    await alertAction?.({ title: 'Search index', message: 'Admin search uses live database queries, so there is no separate search index to rebuild.' });
    return;
  }
  if (/logs/i.test(action)) {
    try {
      const response = await api.request('/admin/system-logs?lines=120');
      const lines = response.lines || [];
      await alertAction?.({ title: 'System logs', message: lines.length ? `${response.file || 'Application log'}\n\n${lines.join('\n')}` : 'No system log entries were found.' });
    } catch (error) {
      await alertAction?.({ title: 'System logs unavailable', message: error.message || 'The system logs could not be loaded.', tone: 'danger' });
    }
    return;
  }
  if (/backup/i.test(action)) {
    navigate('/admin/system-settings?open=backup');
    return;
  }
  await alertAction?.({ title: 'Action unavailable', message: 'This action does not have a backend workflow in this deployment yet.', tone: 'danger' });
}

function contentFilterSummary(filters = {}) {
  const active = Object.entries(filters || {})
    .filter(([, value]) => value !== undefined && value !== null && String(value).trim() !== '' && String(value).trim() !== 'All' && String(value).trim() !== 'All Content')
    .map(([key, value]) => `${key.replace(/_/g, ' ')}: ${value}`);

  return active.length
    ? 'This updates all content matching current filters.'
    : 'This updates all content because no filters are active.';
}

async function runUsersPageAction(action, api, onRefresh, alertAction = null, promptAction = null) {
  if (action === 'Export Users') {
    await downloadAdminFile(api, '/admin/users/export', 'playhub-users.csv', alertAction);
    return;
  }

  if (action === 'User Activity Report') {
    await downloadAdminFile(api, '/admin/users/activity-report', 'playhub-user-activity.csv', alertAction);
    return;
  }

  if (action === 'Invite User') {
    const payload = await promptAction?.({
      type: 'invite',
      title: 'Invite user',
      description: 'Create an account invite with a role assignment.',
      submitLabel: 'Invite user',
    });
    if (!payload) return;
    await adminJsonAction(api, '/admin/users/invite', { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (['Ban User', 'Bulk Suspend Users'].includes(action)) {
    const payload = await promptAction?.({
      type: 'emails',
      title: action === 'Ban User' ? 'Ban user' : 'Bulk suspend users',
      description: 'Enter one or more user emails, separated by comma, space, or new line.',
      submitLabel: action === 'Ban User' ? 'Ban user' : 'Suspend users',
    });
    const emails = parseEmailList(payload?.emails);
    if (!emails.length) return;
    await adminJsonAction(api, '/admin/users/bulk-status', { method: 'POST', body: JSON.stringify({ emails, status: 'suspended' }) }, onRefresh, alertAction);
    return;
  }

  if (action === 'Verify Users') {
    const payload = await promptAction?.({
      type: 'emails',
      title: 'Verify users',
      description: 'Enter one or more user emails to mark as verified.',
      submitLabel: 'Verify users',
    });
    const emails = parseEmailList(payload?.emails);
    if (!emails.length) return;
    await adminJsonAction(api, '/admin/users/bulk-verify', { method: 'POST', body: JSON.stringify({ emails }) }, onRefresh, alertAction);
  }
}

async function runCreatorsPageAction(action, api, onRefresh, alertAction = null, promptAction = null) {
  if (action === 'Export Creators') {
    await downloadAdminFile(api, '/admin/creators/export', 'playhub-creators.csv', alertAction);
    return;
  }

  if (action === 'Invite Creator') {
    const payload = await promptAction?.({
      type: 'invite',
      title: 'Invite creator',
      description: 'Create a creator account invite.',
      defaultRole: 'Creator',
      submitLabel: 'Invite creator',
    });
    if (!payload) return;
    await adminJsonAction(api, '/admin/users/invite', { method: 'POST', body: JSON.stringify({ ...payload, role: 'Creator' }) }, onRefresh, alertAction);
    return;
  }

  if (action === 'Bulk Verify Creators') {
    const payload = await promptAction?.({
      type: 'identifiers',
      title: 'Verify creators',
      description: 'Enter creator email addresses, usernames, or handles to verify their profile badges.',
      inputLabel: 'Creator emails or handles',
      placeholder: 'creator@example.com, @creator-handle',
      submitLabel: 'Verify creators',
    });
    if (!payload?.emails?.trim()) return;
    await adminJsonAction(api, '/admin/creators/bulk-verify', { method: 'POST', body: JSON.stringify({ identifiers: payload.emails }) }, onRefresh, alertAction);
    return;
  }

  if (action === 'Send Announcement') {
    const payload = await promptAction?.({
      type: 'announcement',
      title: 'Send creator announcement',
      description: 'Send an announcement email to all creator accounts.',
      submitLabel: 'Send announcement',
    });
    if (!payload) return;
    await adminJsonAction(api, '/admin/creators/announcement', { method: 'POST', body: JSON.stringify(payload) }, null, alertAction);
  }
}

async function runRolesPageAction(action, { navigate, api, data = {}, onRefresh = null, alertAction = null, promptAction = null, setActiveRoleTab = null, defaultGroupId = '' }) {
  if (action === 'Create New Role') {
    const payload = await promptAction?.({
      type: 'role',
      title: 'Create new role',
      description: 'Create a custom admin role with a permission group.',
      permissionGroups: data.permissionGroups || [],
      submitLabel: 'Create role',
    });
    if (!payload) return;
    await adminJsonAction(api, '/admin/roles', { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'Manage Permission Groups') {
    const payload = await promptAction?.({
      type: 'permission-group',
      title: 'Manage permission groups',
      description: 'Add or edit reusable permission groups for admin roles.',
      permissionGroups: data.permissionGroups || [],
      permissionCatalog: data.permissionCatalog || [],
      defaultGroupId,
      submitLabel: 'Save group',
    });
    if (!payload) return;
    await adminJsonAction(api, '/admin/permission-groups', { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'View Permission Matrix') {
    setActiveRoleTab?.('Permission Matrix');
    return;
  }

  if (action === 'Security Settings') {
    navigate('/admin/system-settings?section=login&highlight=setting-login#setting-login');
  }
}

async function runRoleRowAction(action, row, { api, data = {}, onRefresh = null, confirmAction = null, alertAction = null, promptAction = null }) {
  if (action === 'edit') {
    const payload = await promptAction?.({ type: 'role', title: 'Edit role', description: 'Update this custom admin role and its permission group.', role: row, permissionGroups: data.permissionGroups || [], submitLabel: 'Save role' });
    if (!payload) return;
    await adminJsonAction(api, '/admin/roles', { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'delete') {
    const confirmed = await confirmAction?.({ title: 'Delete role', message: `Delete the custom role "${row.name}"? This cannot be undone.`, confirmLabel: 'Delete role', tone: 'danger' });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/roles/${encodeURIComponent(row.id)}`, { method: 'DELETE' }, onRefresh, alertAction);
  }
}

async function runUserRowAction(action, row, api, navigate, onRefresh, confirmAction = null, editUserAction = null, passwordUserAction = null, roleUserAction = null, emailUserAction = null, banUserAction = null, alertAction = null, promptAction = null) {
  const id = row?.id;
  const person = row?.person || {};
  if (!id) {
    await alertAction?.({ title: 'Missing user id', message: 'User id is missing from this row.', tone: 'danger' });
    return;
  }

  if (action === 'profile') {
    await adminJsonAction(api, `/admin/users/${id}`, {}, null, alertAction);
    navigate?.(row.profileUrl || '/admin/users');
    return;
  }

  if (action === 'activity') {
    const data = await adminJsonAction(api, `/admin/users/${id}/activity`, {}, null, alertAction);
    if (data) await alertAction?.({ title: 'User activity', message: `Videos: ${data.videos}\nComments: ${data.comments}\nSubscriptions: ${data.subscriptions}\nLast activity: ${data.last_activity}` });
    return;
  }

  if (action === 'edit') {
    const payload = await editUserAction?.(row);
    if (!payload) return;
    await adminJsonAction(api, `/admin/users/${id}`, { method: 'PUT', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'password') {
    const payload = await passwordUserAction?.(row);
    if (!payload) return;
    await adminJsonAction(api, `/admin/users/${id}/password`, { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'wallet-credit') {
    const payload = await promptAction?.({
      type: 'wallet-credit',
      title: 'Add wallet funds',
      description: `Add funds to ${person.name || 'this user'} in the website's configured currency.`,
      submitLabel: 'Add funds',
    });
    if (!payload) return;
    await adminJsonAction(api, `/admin/users/${id}/wallet-credit`, { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'role') {
    const payload = await roleUserAction?.(row);
    if (!payload) return;
    await adminJsonAction(api, `/admin/users/${id}/role`, { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'email') {
    const payload = await emailUserAction?.(row);
    if (!payload) return;
    await adminJsonAction(api, `/admin/users/${id}/email`, { method: 'POST', body: JSON.stringify(payload) }, null, alertAction);
    return;
  }

  if (action === 'profile-verify') {
    await adminJsonAction(api, `/admin/users/${id}/profile-verify`, { method: 'POST' }, onRefresh, alertAction);
    return;
  }

  if (action === 'profile-unverify') {
    const confirmed = await confirmAction?.({
      title: 'Remove profile badge',
      message: 'Remove the verified profile badge from this user?',
      confirmLabel: 'Remove badge',
      tone: 'danger',
    });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/users/${id}/profile-verify`, { method: 'DELETE' }, onRefresh, alertAction);
    return;
  }

  if (action === 'suspend') {
    const restore = String(row.status).toLowerCase() === 'suspended';
    const confirmed = await confirmAction?.({
      title: `${restore ? 'Restore' : 'Suspend'} user`,
      message: `${restore ? 'Restore account access for' : 'Suspend account access for'} ${person.name || 'this user'}?`,
      confirmLabel: restore ? 'Restore user' : 'Suspend user',
      tone: restore ? 'primary' : 'danger',
    });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/users/${id}/suspend`, { method: 'POST' }, onRefresh, alertAction);
    return;
  }

  if (action === 'ban') {
    const payload = await banUserAction?.(row);
    if (!payload) return;
    await adminJsonAction(api, `/admin/users/${id}/ban`, { method: 'POST', body: JSON.stringify(payload) }, onRefresh, alertAction);
    return;
  }

  if (action === 'clear-bans') {
    const confirmed = await confirmAction?.({
      title: 'Clear active bans',
      message: `Clear all active bans for ${person.name || 'this user'}?`,
      confirmLabel: 'Clear bans',
      tone: 'primary',
    });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/users/${id}/ban`, { method: 'DELETE' }, onRefresh, alertAction);
    return;
  }

  if (action === 'delete') {
    const confirmed = await confirmAction?.({
      title: 'Delete user',
      message: `Permanently delete ${person.name || 'this user'} and all related account data? This cannot be undone.`,
      confirmLabel: 'Delete user',
      tone: 'danger',
      checkbox: { name: 'deleteVideos', label: 'Also permanently delete all videos uploaded by this user', required: true },
    });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/users/${id}`, { method: 'DELETE', body: JSON.stringify({ delete_videos: Boolean(confirmed.deleteVideos) }) }, onRefresh, alertAction);
  }
}

async function runCreatorRowAction(action, row, api, navigate, onRefresh, confirmAction = null, alertAction = null) {
  if (action === 'view') {
    navigate?.(row.profileUrl || (row.slug ? `/channel/${row.slug}` : '/admin/creators'));
    return;
  }
  if (action === 'content') {
    navigate?.(`/admin/content?search=${encodeURIComponent(row.person?.handle || row.person?.name || '')}`);
    return;
  }
  if (action === 'verify' && row.user_id) {
    await adminJsonAction(api, `/admin/users/${row.user_id}/profile-verify`, { method: 'POST' }, onRefresh, alertAction);
    return;
  }
  if (action === 'unverify' && row.user_id) {
    const confirmed = await confirmAction?.({
      title: 'Remove profile badge',
      message: 'Remove the verified profile badge from this user?',
      confirmLabel: 'Remove badge',
      tone: 'danger',
    });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/users/${row.user_id}/profile-verify`, { method: 'DELETE' }, onRefresh, alertAction);
  }
}

async function runContentRowAction(action, row, api, navigate, onRefresh, confirmAction = null, alertAction = null) {
  if (action === 'view') {
    navigate?.(row.watchUrl || (row.slug ? `/watch/${row.slug}` : '/admin/content'));
    return;
  }
  if (action === 'edit') {
    navigate?.(row.editUrl || (row.slug ? `/studio/videos/${row.slug}/edit` : '/admin/content'));
    return;
  }
  if (['public', 'unlisted', 'private'].includes(action)) {
    await adminJsonAction(api, `/admin/content/videos/${row.id}/visibility`, { method: 'PUT', body: JSON.stringify({ visibility: action }) }, onRefresh, alertAction);
    return;
  }
  if (action === 'approve') {
    await adminJsonAction(api, `/admin/content/videos/${row.id}/approve`, { method: 'POST' }, onRefresh, alertAction);
    return;
  }
  if (action === 'delete') {
    const confirmed = await confirmAction?.({
      title: 'Delete video',
      message: `Delete "${row.title || 'this video'}"? This removes the video and its media files.`,
      confirmLabel: 'Delete video',
      tone: 'danger',
    });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/content/videos/${row.id}`, { method: 'DELETE' }, onRefresh, alertAction);
  }
}

async function runLiveRowAction(action, row, api, navigate, onRefresh, confirmAction = null, alertAction = null) {
  if (action === 'view') {
    navigate?.(row.watchUrl || (row.slug ? `/watch/${row.slug}` : '/admin/live-streams'));
    return;
  }
  if (action === 'reports') {
    navigate?.(`/admin/reports?search=${encodeURIComponent(row.title || '')}`);
    return;
  }
  if (action === 'settings') {
    navigate?.('/admin/system-settings?section=live-streaming&highlight=setting-live-streaming#setting-live-streaming');
    return;
  }
  if (action === 'delete' && row.id) {
    const confirmed = await confirmAction?.({
      title: 'Delete live stream',
      message: `Delete "${row.title || 'this live stream'}"? This removes the stream, its replay, and associated media files.`,
      confirmLabel: 'Delete live stream',
      tone: 'danger',
    });
    if (!confirmed) return;
    await adminJsonAction(api, `/admin/live-streams/${row.id}`, { method: 'DELETE' }, onRefresh, alertAction);
    return;
  }
  if (action === 'hide' && row.id) {
    await adminJsonAction(api, `/admin/content/videos/${row.id}/visibility`, { method: 'PUT', body: JSON.stringify({ visibility: 'private' }) }, onRefresh, alertAction);
  }
}

function adminReportPath(row) {
  const type = String(row.type || '').toLowerCase();
  if (type === 'video' && row.video_id) return `/admin/reports/videos/${row.video_id}`;
  if (type === 'profile' && row.user_id) return `/admin/reports/users/${row.user_id}`;
  if (row.comment_id) return `/admin/reports/comments/${row.comment_id}`;
  return '';
}

async function runReportRowAction(action, row, api, navigate, onRefresh, confirmAction = null, alertAction = null) {
  if (action === 'view') {
    navigate?.(row.target_url || '/admin/reports');
    return;
  }

  const type = String(row.type).toLowerCase();
  const path = adminReportPath(row);
  if (!path) {
    await alertAction?.({ title: 'Missing report target', message: 'This report is missing the content id required for action.', tone: 'danger' });
    return;
  }

  if (action === 'dismiss') {
    await adminJsonAction(api, `${path}/dismiss`, { method: 'POST' }, onRefresh, alertAction);
    return;
  }
  if (action === 'delete') {
    if (type === 'profile') return;
    const isVideo = type === 'video';
    const confirmed = await confirmAction?.({
      title: `Delete reported ${isVideo ? 'video' : 'comment'}`,
      message: `Delete "${row.title || 'this reported item'}"?`,
      confirmLabel: isVideo ? 'Delete video' : 'Delete comment',
      tone: 'danger',
    });
    if (!confirmed) return;
    await adminJsonAction(api, path, { method: 'DELETE' }, onRefresh, alertAction);
  }
}

async function runSupportRowAction(action, row, api, navigate, onRefresh, alertAction = null) {
  if (action === 'open') {
    if (row.raw_id) {
      navigate?.(`/admin/support?search=${encodeURIComponent(row.id || '')}&highlight=support-${row.raw_id}#support-${row.raw_id}`);
      return;
    }
    navigate?.(String(row.category || '').toLowerCase().includes('moderation') ? '/admin/reports' : '/admin/system-settings');
    return;
  }
  if (action === 'reports') {
    navigate?.('/admin/reports');
    return;
  }
  if (action === 'resolve' && row.raw_id) {
    await adminJsonAction(api, `/admin/support/contact-messages/${row.raw_id}/resolve`, { method: 'POST' }, onRefresh, alertAction);
    return;
  }
  if (action.startsWith('status:') && row.raw_id) {
    await adminJsonAction(api, `/admin/support/contact-messages/${row.raw_id}/status`, { method: 'PUT', body: JSON.stringify({ status: action.slice(7) }) }, onRefresh, alertAction);
  }
}

function UsersPage({ navigate, data = {}, api = null, onRefresh = () => {}, confirmAction = null, editUserAction = null, passwordUserAction = null, roleUserAction = null, emailUserAction = null, banUserAction = null, alertAction = null, promptAction = null, routeSearch = '', canManageAdmin = true }) {
  const fallbackRows = data.rows || [];
  const routeSearchTerm = adminRouteParam(routeSearch, 'search');
  const routeRole = adminRouteParam(routeSearch, 'role');
  const routeEditUserId = adminRouteParam(routeSearch, 'edit');
  const [tableData, setTableData] = useState(null);
  const [tablePage, setTablePage] = useState(1);
  const [tableLoading, setTableLoading] = useState(false);
  const [openedEditUserId, setOpenedEditUserId] = useState('');
  const [activeTab, setActiveTab] = useState('All Users');
  const [searchTerm, setSearchTerm] = useState(routeSearchTerm);
  const [roleFilter, setRoleFilter] = useState(routeRole || 'All');
  const [statusFilter, setStatusFilter] = useState('All');
  const [verificationFilter, setVerificationFilter] = useState('All');
  const [moreFiltersOpen, setMoreFiltersOpen] = useState(false);
  const [domainFilter, setDomainFilter] = useState('');
  const [joinedAfter, setJoinedAfter] = useState('');
  const [joinedBefore, setJoinedBefore] = useState('');
  useEffect(() => setRoleFilter(routeRole || 'All'), [routeRole]);
  const runQuickAction = (action) => runUsersPageAction(action, api, onRefresh, alertAction, promptAction);
  const tabs = data.tabs || [['All Users', '0'], ['Active', '0'], ['Creators', '0'], ['Suspended', '0'], ['Verified', '0']];
  const rows = tableData?.rows || fallbackRows;
  const highlightId = useAdminRouteTarget(routeSearch, rows.length);
  const totalRows = tableData?.total ?? fallbackRows.length;
  const tableFrom = tableData?.from ?? (rows.length ? 1 : 0);
  const tableTo = tableData?.to ?? rows.length;
  const lastPage = tableData?.last_page || 1;
  const roleOptions = ['All', 'User', 'Creator', 'Moderator', 'Admin'];
  const statusOptions = ['All', 'Active', 'Suspended', 'Pending', 'Deleted'];
  const pageStart = Math.max(1, Math.min(tablePage - 2, Math.max(lastPage - 4, 1)));
  const paginationPages = Array.from({ length: Math.min(5, lastPage - pageStart + 1) }, (_, index) => String(pageStart + index));

  useEffect(() => {
    setTablePage(1);
  }, [activeTab, searchTerm, roleFilter, statusFilter, verificationFilter, domainFilter, joinedAfter, joinedBefore]);

  useEffect(() => {
    setSearchTerm(routeSearchTerm);
  }, [routeSearchTerm]);

  useEffect(() => {
    setOpenedEditUserId('');
  }, [routeEditUserId]);

  useEffect(() => {
    const userId = String(routeEditUserId || '').replace(/\D/g, '');
    if (!userId || openedEditUserId === userId || !api?.request || !editUserAction) return undefined;

    let cancelled = false;
    setOpenedEditUserId(userId);
    api.request(`/admin/users/${userId}`)
      .then(async (payload) => {
        const row = payload?.user;
        if (cancelled || !row) return;
        const editPayload = await editUserAction(row);
        if (cancelled || !editPayload) return;
        const response = await adminJsonAction(api, `/admin/users/${userId}`, { method: 'PUT', body: JSON.stringify(editPayload) }, onRefresh, alertAction);
        if (response?.user) {
          setTableData((current) => current ? { ...current, rows: (current.rows || []).map((item) => Number(item.id) === Number(response.user.id) ? response.user : item) } : current);
        }
      })
      .catch((error) => {
        if (!cancelled) alertAction?.({ title: 'Edit user failed', message: error.message || 'Unable to open user editor.', tone: 'danger' });
      });

    return () => {
      cancelled = true;
    };
  }, [api, alertAction, editUserAction, onRefresh, openedEditUserId, routeEditUserId]);

  useEffect(() => {
    if (!api?.request) return undefined;

    let cancelled = false;
    const timeout = setTimeout(() => {
      const params = new URLSearchParams({ page: String(tablePage), per_page: '25' });
      if (searchTerm.trim()) params.set('search', searchTerm.trim());
      if (activeTab !== 'All Users') params.set('tab', activeTab);
      if (roleFilter !== 'All') params.set('role', roleFilter);
      if (statusFilter !== 'All') params.set('status', statusFilter);
      if (verificationFilter !== 'All') params.set('verification', verificationFilter);
      if (domainFilter.trim()) params.set('email_domain', domainFilter.trim());
      if (joinedAfter) params.set('joined_after', joinedAfter);
      if (joinedBefore) params.set('joined_before', joinedBefore);

      setTableLoading(true);
      api.request(`/admin/users?${params.toString()}`)
        .then((payload) => {
          if (!cancelled) setTableData(payload || null);
        })
        .catch((error) => {
          if (!cancelled) alertAction?.({ title: 'Users search failed', message: error.message || 'Unable to search users.', tone: 'danger' });
        })
        .finally(() => {
          if (!cancelled) setTableLoading(false);
        });
    }, searchTerm.trim() ? 300 : 0);

    return () => {
      cancelled = true;
      clearTimeout(timeout);
    };
  }, [api, activeTab, searchTerm, roleFilter, statusFilter, verificationFilter, domainFilter, joinedAfter, joinedBefore, tablePage, data.rows]);

  const resetUserFilters = () => {
    setActiveTab('All Users');
    setSearchTerm('');
    setRoleFilter('All');
    setStatusFilter('All');
    setVerificationFilter('All');
    setDomainFilter('');
    setJoinedAfter('');
    setJoinedBefore('');
    setMoreFiltersOpen(false);
  };

  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid users-grid">
        <div className="admin-main-stack">
          <TablePanel flush>
            <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} />
            <FilterBar
              search="Search by name, email, or username..."
              value={searchTerm}
              onSearch={setSearchTerm}
              selects={[
                { label: 'Role', value: roleFilter, options: roleOptions, onChange: setRoleFilter },
                { label: 'Status', value: statusFilter, options: statusOptions, onChange: setStatusFilter },
                { label: 'Verification', value: verificationFilter, options: ['All', 'Verified', 'Unverified'], onChange: setVerificationFilter },
              ]}
              reset
              onMore={() => setMoreFiltersOpen((open) => !open)}
              onReset={resetUserFilters}
              moreActive={moreFiltersOpen}
            />
            {moreFiltersOpen && <div className="admin-filter-extra">
              <label><span>Email domain</span><input value={domainFilter} onChange={(event) => setDomainFilter(event.target.value)} placeholder="mail.com" /></label>
              <label><span>Joined after</span><input type="date" value={joinedAfter} onClick={(event) => event.currentTarget.showPicker?.()} onFocus={(event) => event.currentTarget.showPicker?.()} onChange={(event) => setJoinedAfter(event.target.value)} /></label>
              <label><span>Joined before</span><input type="date" value={joinedBefore} onClick={(event) => event.currentTarget.showPicker?.()} onFocus={(event) => event.currentTarget.showPicker?.()} onChange={(event) => setJoinedBefore(event.target.value)} /></label>
            </div>}
            {tableLoading && <div className="admin-table-loading">Searching users...</div>}
            <UsersTable rows={rows} navigate={navigate} api={api} onRefresh={onRefresh} confirmAction={confirmAction} editUserAction={editUserAction} passwordUserAction={passwordUserAction} roleUserAction={roleUserAction} emailUserAction={emailUserAction} banUserAction={banUserAction} alertAction={alertAction} promptAction={promptAction} highlightId={highlightId} canManageAdmin={canManageAdmin} />
            <TableFooter text={`Showing ${tableFrom} to ${tableTo} of ${totalRows} users`} pages={paginationPages} activePage={tablePage} lastPage={lastPage} onPageChange={setTablePage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Recent Signups" action="View all" onAction={() => navigate('/admin/users')}><ActivityList rows={data.recentSignups || []} /></SideCard>
          <SideCard title="User Segments" action="View all" onAction={() => navigate('/admin/users')}><DonutMini items={data.segments || []} center={data.segmentCenter || data.tabs?.[0]?.[1] || '0'} sub="Total" /></SideCard>
          {canManageAdmin && <QuickActionList actions={['Ban User', 'Verify Users', 'Bulk Suspend Users', 'Export Users', 'User Activity Report']} onAction={runQuickAction} />}
        </aside>
      </div>
    </>
  );
}

function CreatorsPage({ navigate, data = {}, api = null, onRefresh = () => {}, confirmAction = null, alertAction = null, promptAction = null, routeSearch = '' }) {
  const rows = data.rows || [];
  const tabs = data.tabs || [['All Creators', '0'], ['Active', '0'], ['Verified', '0'], ['Partnered', '0'], ['Applied', '0'], ['Suspended', '0']];
  const [activeTab, setActiveTab] = useState(tabs[0]?.[0] || 'All Creators');
  const routeSearchTerm = adminRouteParam(routeSearch, 'search');
  const [tableSearch, setTableSearch] = useState(routeSearchTerm);
  const [tablePage, setTablePage] = useState(1);
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [statusFilter, setStatusFilter] = useState('All');
  const [verificationFilter, setVerificationFilter] = useState('All');
  useEffect(() => setTableSearch(routeSearchTerm), [routeSearchTerm]);
  useEffect(() => setTablePage(1), [activeTab, tableSearch, categoryFilter, statusFilter, verificationFilter]);
  const highlightId = useAdminRouteTarget(routeSearch, rows.length);
  const searchedRows = filterAdminRows(rows, tableSearch, (row) => [row.person?.name, row.person?.handle, row.person?.email, row.category, row.verification]);
  const filteredRows = searchedRows.filter((row) => {
    const tabMatches = activeTab === 'Active' ? row.active === true
      : activeTab === 'Verified' ? row.verification === 'Verified'
        : activeTab === 'Partnered' ? row.partnered === true
          : activeTab === 'Applied' ? row.applied === true
            : activeTab === 'Suspended' ? row.status === 'Suspended'
              : true;
    return tabMatches
      && (categoryFilter === 'All' || row.category === categoryFilter)
      && (statusFilter === 'All' || row.status === statusFilter)
      && (verificationFilter === 'All' || row.verification === verificationFilter);
  });
  const tablePagination = paginateAdminRows(filteredRows, tablePage);
  const runQuickAction = (action) => runCreatorsPageAction(action, api, onRefresh, alertAction, promptAction);
  const categories = ['All', ...Array.from(new Set(rows.map((row) => row.category).filter(Boolean)))];
  const statuses = ['All', ...Array.from(new Set(rows.map((row) => row.status).filter(Boolean)))];
  const verifications = ['All', ...Array.from(new Set(rows.map((row) => row.verification).filter(Boolean)))];
  const resetFilters = () => { setTableSearch(''); setCategoryFilter('All'); setStatusFilter('All'); setVerificationFilter('All'); };

  return (
    <>
      <div className="admin-content-grid creators-grid">
        <div className="admin-main-stack">
          <MetricGrid metrics={data.metrics || []} columns={4} />
          <TablePanel flush>
            <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} />
            <FilterBar search="Search by name, handle, or email..." value={tableSearch} onSearch={setTableSearch} selects={[{ label: 'Category', value: categoryFilter, options: categories, onChange: setCategoryFilter }, { label: 'Status', value: statusFilter, options: statuses, onChange: setStatusFilter }, { label: 'Verification', value: verificationFilter, options: verifications, onChange: setVerificationFilter }]} reset onReset={resetFilters} />
            <CreatorsTable rows={tablePagination.rows} navigate={navigate} api={api} onRefresh={onRefresh} confirmAction={confirmAction} alertAction={alertAction} highlightId={highlightId} />
            <TableFooter text={`Showing ${tablePagination.from} to ${tablePagination.to} of ${tablePagination.total} creators`} pages={tablePagination.pages} activePage={tablePagination.activePage} lastPage={tablePagination.lastPage} onPageChange={setTablePage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack creators-side-stack">
          <SideCard title="Avg Revenue per Creator" action="View all" className="admin-mini-side-card" onAction={() => navigate('/admin/monetization')}>
            <div className="admin-big-side-value">{data.averageRevenue || '$0'}</div>
            <RankedPeople rows={data.topRevenueCreators || []} />
          </SideCard>
          <SideCard title="Verification Queue" action="View pending" onAction={() => { setActiveTab('All Creators'); setVerificationFilter('Pending'); }}><ActivityList rows={data.verificationQueue || []} /></SideCard>
          <SideCard title="Creator Segments" action="Clear filters" onAction={resetFilters}><DonutMini items={data.segments || []} center={data.segmentCenter || data.tabs?.[0]?.[1] || '0'} sub="Total" /></SideCard>
          <QuickActionList actions={['Invite Creator', 'Bulk Verify Creators', 'Send Announcement', 'Export Creators']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

function SystemUpdatesPage({ api = null, confirmAction = null, alertAction = null, promptAction = null }) {
  const [payload, setPayload] = useState(null);
  const [loading, setLoading] = useState(true);
  const [checking, setChecking] = useState(false);
  const [installing, setInstalling] = useState(false);
  const [error, setError] = useState('');
  const [logUpdate, setLogUpdate] = useState(null);
  const [logEntries, setLogEntries] = useState([]);
  const [logLoading, setLogLoading] = useState(false);
  const initialCheckRef = useRef(false);
  const checkingRef = useRef(false);
  const notifiedRef = useRef('');

  const loadDashboard = useCallback(async (showLoader = false) => {
    if (!api?.request) {
      setError('Admin API client is unavailable.');
      setLoading(false);
      return null;
    }
    if (showLoader) setLoading(true);
    try {
      const response = await api.request('/admin/system-updates', { trackPageLoading: showLoader });
      setPayload(response);
      setError('');
      return response;
    } catch (requestError) {
      setError(requestError.message || 'System update information could not be loaded.');
      return null;
    } finally {
      if (showLoader) setLoading(false);
    }
  }, [api]);

  const checkUpdates = useCallback(async (silent = false) => {
    if (!api?.request || checkingRef.current) return null;
    checkingRef.current = true;
    setChecking(true);
    if (!silent) setError('');
    try {
      const available = await api.request('/admin/system-updates/check', { method: 'POST', body: '{}', trackPageLoading: !silent });
      setPayload((current) => ({ ...(current || {}), current_version: available.current_version, available_update: available }));
      return available;
    } catch (requestError) {
      setError(requestError.message || 'PlayHub could not contact the trusted update repository.');
      return null;
    } finally {
      checkingRef.current = false;
      setChecking(false);
    }
  }, [api]);

  useEffect(() => {
    let mounted = true;
    loadDashboard(true).then((response) => {
      if (!mounted || initialCheckRef.current || response?.active_update || response?.available_update) return;
      initialCheckRef.current = true;
      checkUpdates(true);
    });
    return () => { mounted = false; };
  }, [loadDashboard, checkUpdates]);

  const active = payload?.active_update;
  useEffect(() => {
    if (!active?.uuid || !api?.request) return undefined;
    const poll = window.setInterval(async () => {
      try {
        const response = await api.request(`/admin/system-updates/${encodeURIComponent(active.uuid)}`, { trackPageLoading: false });
        const update = response.update;
        const terminal = ['completed', 'failed', 'rolled_back'].includes(update?.status);
        setPayload((current) => ({ ...(current || {}), active_update: terminal ? null : update }));
        if (terminal) {
          window.clearInterval(poll);
          await loadDashboard(false);
          const noticeKey = `${update.uuid}:${update.status}`;
          if (notifiedRef.current !== noticeKey) {
            notifiedRef.current = noticeKey;
            await alertAction?.({
              title: update.status === 'completed' ? 'PlayHub updated' : update.status === 'rolled_back' ? 'Update rolled back' : 'Update failed',
              message: update.status === 'completed' ? `PlayHub ${update.to_version} was installed successfully.` : (update.error_details || 'The update did not complete.'),
              tone: update.status === 'completed' ? 'primary' : 'danger',
            });
          }
        }
      } catch (requestError) {
        setError(requestError.message || 'Update progress could not be refreshed.');
      }
    }, 2000);
    return () => window.clearInterval(poll);
  }, [active?.uuid, api, loadDashboard, alertAction]);

  const install = async () => {
    const available = payload?.available_update;
    if (!available || available.up_to_date || installing) return;
    const confirmed = confirmAction ? await confirmAction({
      title: `Update PlayHub to ${available.latest_version}?`,
      message: `PlayHub will verify ${available.update_count} signed release package${available.update_count === 1 ? '' : 's'}, enter maintenance mode, create a file and database recovery point, and roll back automatically if a critical step fails.`,
      confirmLabel: 'Update Now',
      tone: 'primary',
    }) : true;
    if (!confirmed) return;
    if (!promptAction) {
      setError('The license verification dialog is unavailable.');
      return;
    }
    const license = await promptAction({
      type: 'license',
      title: 'Verify PlayHub license',
      description: 'Enter the license used for this PlayHub installation before the update can begin.',
      inputLabel: 'License key or purchase code',
      placeholder: 'KNL-XXXX-... or Envato purchase code',
      submitLabel: 'Verify and Update',
    });
    if (!license?.license_key) return;

    setInstalling(true);
    setError('');
    try {
      const response = await api.request('/admin/system-updates/install', {
        method: 'POST',
        body: JSON.stringify({ target_version: available.latest_version, license_key: license.license_key }),
      });
      const terminal = ['completed', 'failed', 'rolled_back'].includes(response.update?.status);
      setPayload((current) => ({ ...(current || {}), active_update: terminal ? null : response.update }));
      if (terminal) await loadDashboard(false);
      await alertAction?.({ title: 'Update queued', message: response.message });
    } catch (requestError) {
      setError(requestError.message || 'The PlayHub update could not be started.');
    } finally {
      setInstalling(false);
    }
  };

  const openLog = async (update) => {
    setLogUpdate(update);
    setLogEntries([]);
    setLogLoading(true);
    try {
      const response = await api.request(`/admin/system-updates/${encodeURIComponent(update.uuid)}/log?lines=1000`, { trackPageLoading: false });
      setLogEntries(Array.isArray(response.entries) ? response.entries : []);
    } catch (requestError) {
      setLogEntries([{ level: 'error', timestamp: new Date().toISOString(), message: requestError.message || 'The update log could not be loaded.' }]);
    } finally {
      setLogLoading(false);
    }
  };

  const available = payload?.available_update;
  const releases = Array.isArray(available?.releases) ? available.releases : [];
  const latestRelease = releases[releases.length - 1];
  const preflight = Array.isArray(available?.preflight) ? available.preflight : [];
  const preflightPassed = preflight.length > 0 && preflight.every((check) => check.passed);
  const releaseRequirements = releases.map((release) => release.requirements || {});
  const phpRequirements = [...new Set(releaseRequirements.map((requirements) => requirements.php_min).filter(Boolean))];
  const laravelRequirements = [...new Set(releaseRequirements.map((requirements) => requirements.laravel_min).filter(Boolean))];
  const requiredExtensions = [...new Set(releaseRequirements.flatMap((requirements) => Array.isArray(requirements.extensions) ? requirements.extensions : []))].sort();
  const databaseDriverSets = releaseRequirements.map((requirements) => Array.isArray(requirements.database?.drivers) ? requirements.database.drivers : []);
  const databaseDrivers = databaseDriverSets.length ? databaseDriverSets[0].filter((driver) => databaseDriverSets.every((drivers) => drivers.includes(driver))) : [];
  const changelog = releases.reduce((result, release) => {
    Object.entries(release.changelog || {}).forEach(([section, items]) => {
      result[section] = [...(result[section] || []), ...(Array.isArray(items) ? items : [])];
    });
    return result;
  }, {});
  const history = Array.isArray(payload?.history) ? payload.history : [];

  if (loading && !payload) return <div className="admin-table-loading">Loading verified update status...</div>;

  return <>
    <div className="admin-update-overview">
      <section className="admin-update-current">
        <span className={`admin-update-mark ${active ? 'working' : available && !available.up_to_date ? 'available' : 'current'}`}><AdminIcon name={active ? 'refresh' : 'shield'} size={26} /></span>
        <div>
          <small>Current Version</small>
          <h2>PlayHub v{payload?.current_version || '-'}</h2>
          <p>{active ? active.current_step : available?.up_to_date ? 'This installation is up to date.' : available ? `PlayHub v${available.latest_version} is available.` : 'Update status has not been checked yet.'}</p>
        </div>
        <button type="button" className="admin-outline-action" onClick={() => checkUpdates(false)} disabled={checking || Boolean(active)}><AdminIcon name="refresh" size={16} />{checking ? 'Checking...' : 'Check for Updates'}</button>
      </section>

      {active && <section className="admin-update-progress" aria-live="polite">
        <div className="admin-update-progress-head"><span><i className="admin-update-spinner" /><strong>{active.current_step || 'Installing update'}</strong></span><b>{Number(active.progress || 0)}%</b></div>
        <div className="admin-update-progress-track"><i style={{ width: `${Math.max(1, Number(active.progress || 0))}%` }} /></div>
        <p>{active.backup_available ? 'The recovery point is ready. ' : 'The recovery point will be recorded before deployment. '}The backend owns this transaction, so closing this page will not interrupt it.</p>
        <button type="button" onClick={() => openLog(active)}>View live log</button>
      </section>}

      {error && <div className="admin-update-alert danger"><AdminIcon name="warning" size={18} /><span><strong>Update check needs attention</strong><small>{error}</small></span></div>}
      {!!payload?.configuration?.issues?.length && <div className="admin-update-alert danger"><AdminIcon name="warning" size={18} /><span><strong>Updater configuration incomplete</strong><small>{payload.configuration.issues.join(' ')}</small></span></div>}

      {available?.up_to_date && !active && <section className="admin-update-empty"><AdminIcon name="resolved" size={31} /><strong>PlayHub is up to date</strong><p>Version {available.current_version} is the newest compatible release published by the trusted repository.</p><small>Last checked {formatUpdateDate(available.checked_at, true)}</small></section>}

      {available && !available.up_to_date && !active && <div className="admin-update-layout">
        <main className="admin-update-main">
          <section className="admin-update-release-head">
            <div><small>Available release</small><h2>PlayHub v{available.latest_version}</h2><p>{releases.length > 1 ? `${releases.length} sequential signed updates will be installed automatically. Latest release published ${formatUpdateDate(latestRelease?.released_at)}.` : `Published ${formatUpdateDate(latestRelease?.released_at)}.`}</p></div>
            <div><span>{formatBackupBytes(available.total_size_bytes)}</span><small>delta download</small></div>
          </section>
          <section className="admin-update-change-flags">
            <span className={releases.some((release) => release.changes?.backend) ? 'included' : ''}><AdminIcon name="settings" size={16} />Backend</span>
            <span className={releases.some((release) => release.changes?.frontend) ? 'included' : ''}><AdminIcon name="desktop" size={16} />Frontend assets</span>
            <span className={releases.some((release) => release.database_migrations) ? 'included' : ''}><AdminIcon name="storage" size={16} />Database migrations</span>
            <span className={releases.some((release) => release.maintenance_required) ? 'included' : ''}><AdminIcon name="clock" size={16} />Maintenance mode</span>
          </section>
          <section className="admin-update-requirements">
            <h3>Release requirements</h3>
            <dl>
              <div><dt>PHP</dt><dd>{phpRequirements.length ? phpRequirements.map((version) => `${version}+`).join(', ') : '-'}</dd></div>
              <div><dt>Laravel</dt><dd>{laravelRequirements.length ? laravelRequirements.map((version) => `${version}+`).join(', ') : '-'}</dd></div>
              <div><dt>Database</dt><dd>{databaseDrivers.length ? databaseDrivers.join(', ') : '-'}</dd></div>
              <div><dt>Extensions</dt><dd>{requiredExtensions.length ? requiredExtensions.join(', ') : '-'}</dd></div>
            </dl>
          </section>
          <section className="admin-update-changelog"><h3>What changed</h3>{['new', 'improved', 'fixed', 'security'].map((section) => (changelog[section] || []).length ? <div key={section}><strong>{section[0].toUpperCase() + section.slice(1)}</strong><ul>{changelog[section].map((item, index) => <li key={`${section}-${index}`}>{item}</li>)}</ul></div> : null)}</section>
        </main>
        <aside className="admin-update-side">
          <section><h3>Preflight checks</h3><div className="admin-update-checks">{preflight.map((check) => <div key={check.key} className={check.passed ? 'passed' : 'failed'}><AdminIcon name={check.passed ? 'resolved' : 'warning'} size={16} /><span><strong>{check.label}</strong><small>{check.detail}</small></span></div>)}</div></section>
          <section className="admin-update-recovery"><AdminIcon name="shield" size={20} /><span><strong>Automatic recovery point</strong><small>Files and the database are backed up before deployment. Failed critical steps trigger rollback.</small></span></section>
          <button type="button" className="admin-update-install" onClick={install} disabled={installing || !preflightPassed}>{installing ? 'Queuing update...' : `Update to v${available.latest_version}`}</button>
          {!preflightPassed && <small className="admin-update-disabled-note">Resolve failed preflight checks before installation.</small>}
        </aside>
      </div>}
    </div>

    <section className="admin-update-history">
      <header><div><h2>Update History</h2><p>Completed, failed, and recovered update transactions.</p></div></header>
      {history.length ? <div className="admin-update-history-list">{history.map((update) => <div key={update.uuid}>
        <span className={`admin-update-history-status ${update.status}`}><i />{formatUpdateStatus(update.status)}</span>
        <span><strong>{update.from_version} <AdminIcon name="chevronRight" size={13} /> {update.to_version}</strong><small>{formatUpdateDate(update.completed_at || update.failed_at || update.created_at, true)}{update.installer?.name ? ` · ${update.installer.name}` : ''}</small></span>
        <span><strong>{update.current_step || formatUpdateStatus(update.status)}</strong><small>{update.error_details || `${update.progress || 0}% recorded progress`}</small></span>
        <button type="button" onClick={() => openLog(update)}>View log</button>
      </div>)}</div> : <div className="admin-update-history-empty">No update transactions have been recorded.</div>}
    </section>

    {logUpdate && <div className="admin-update-log-overlay" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setLogUpdate(null); }}>
      <section className="admin-update-log-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-update-log-title">
        <header><div><h2 id="admin-update-log-title">Update Log</h2><p>{logUpdate.from_version} to {logUpdate.to_version}</p></div><button type="button" onClick={() => setLogUpdate(null)} aria-label="Close update log"><AdminIcon name="close" size={20} /></button></header>
        <div className="admin-update-log-content">{logLoading ? <div className="admin-table-loading">Loading protected update log...</div> : logEntries.length ? logEntries.map((entry, index) => <div key={`${entry.timestamp}-${index}`} className={`level-${entry.level || 'info'}`}><time>{formatUpdateDate(entry.timestamp, true)}</time><strong>{String(entry.level || 'info').toUpperCase()}</strong><p>{entry.message}</p>{entry.context && Object.keys(entry.context).length > 0 && <pre>{JSON.stringify(entry.context, null, 2)}</pre>}</div>) : <p>No log entries were recorded.</p>}</div>
        <footer><button type="button" className="admin-outline-action" onClick={() => openLog(logUpdate)} disabled={logLoading}><AdminIcon name="refresh" size={15} />Refresh</button><button type="button" className="admin-confirm-button primary" onClick={() => setLogUpdate(null)}>Close</button></footer>
      </section>
    </div>}
  </>;
}

function formatUpdateDate(value, includeTime = false) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return includeTime ? date.toLocaleString() : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatUpdateStatus(status) {
  const labels = { queued: 'Queued', preflight: 'Preflight', downloading: 'Downloading', verifying: 'Verifying', preparing: 'Preparing', backing_up: 'Backing up', installing_files: 'Installing files', installing_dependencies: 'Dependencies', migrating_database: 'Database', rebuilding_frontend: 'Frontend', refreshing_caches: 'Caches', health_check: 'Health check', finalizing: 'Finalizing', completed: 'Completed', failed: 'Failed', rolling_back: 'Rolling back', rolled_back: 'Rolled back' };
  return labels[status] || String(status || 'Unknown').replaceAll('_', ' ');
}

function PluginsPage({ api = null, confirmAction = null, alertAction = null }) {
  const [payload, setPayload] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('All');
  const [busy, setBusy] = useState('');
  const [settingsPlugin, setSettingsPlugin] = useState(null);
  const [detailsPlugin, setDetailsPlugin] = useState(null);
  const packageInputRef = useRef(null);

  const loadPlugins = useCallback(async () => {
    if (!api?.request) {
      setError('Admin API client is unavailable.');
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    try {
      setPayload(await api.request('/admin/plugins'));
    } catch (requestError) {
      setError(requestError.message || 'Plugin information could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => { loadPlugins(); }, [loadPlugins]);

  const uploadPackage = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !api?.request || busy) return;
    if (!file.name.toLowerCase().endsWith('.zip')) {
      await alertAction?.({ title: 'Invalid plugin package', message: 'Select a ZIP package containing a plugin.json manifest.', tone: 'danger' });
      return;
    }

    const formData = new FormData();
    formData.append('package', file);
    setBusy('upload');
    try {
      const response = await api.request('/admin/plugins', { method: 'POST', body: formData });
      await loadPlugins();
      await alertAction?.({ title: response.updated ? 'Plugin updated' : 'Plugin installed', message: response.message || 'The package was installed in disabled state.' });
    } catch (requestError) {
      await alertAction?.({ title: 'Unable to install plugin', message: requestError.message || 'The plugin package could not be installed.', tone: 'danger' });
    } finally {
      setBusy('');
    }
  };

  const runPluginAction = async (plugin, action) => {
    if (!api?.request || busy) return;
    if (action === 'enable') {
      const confirmed = await confirmAction?.({
        title: `Enable ${plugin.name}?`,
        message: 'Enabled plugins run trusted PHP and JavaScript with the same application privileges as PlayHub.',
        confirmLabel: 'Enable plugin',
        tone: 'danger',
        checkbox: { name: 'trusted', label: 'I trust this plugin package and its source.', required: true },
      });
      if (!confirmed) return;
    }
    if (action === 'disable') {
      const confirmed = await confirmAction?.({
        title: `Disable ${plugin.name}?`,
        message: 'Its providers, hooks, and frontend assets will stop loading on the next request.',
        confirmLabel: 'Disable plugin',
      });
      if (!confirmed) return;
    }
    if (action === 'remove') {
      const confirmed = await confirmAction?.({
        title: `Remove ${plugin.name}?`,
        message: 'The plugin package and saved settings will be permanently removed. Plugin-created database tables are retained unless its uninstall hook removes them.',
        confirmLabel: 'Remove plugin',
        tone: 'danger',
      });
      if (!confirmed) return;
    }

    const requests = {
      enable: { path: `/admin/plugins/${encodeURIComponent(plugin.id)}/enable`, method: 'POST' },
      disable: { path: `/admin/plugins/${encodeURIComponent(plugin.id)}/disable`, method: 'POST' },
      remove: { path: `/admin/plugins/${encodeURIComponent(plugin.id)}`, method: 'DELETE' },
    };
    const request = requests[action];
    if (!request) return;

    setBusy(`${action}:${plugin.id}`);
    try {
      const response = await api.request(request.path, { method: request.method });
      setSettingsPlugin(null);
      setDetailsPlugin(null);
      await loadPlugins();
      await alertAction?.({ title: `${plugin.name} ${action === 'remove' ? 'removed' : `${action}d`}`, message: response.message || 'Plugin state updated.' });
    } catch (requestError) {
      await loadPlugins();
      await alertAction?.({ title: `Unable to ${action} plugin`, message: requestError.message || 'The plugin operation failed.', tone: 'danger' });
    } finally {
      setBusy('');
    }
  };

  const savePluginSettings = async (values) => {
    if (!settingsPlugin || !api?.request || busy) return 'The plugin settings cannot be saved right now.';
    setBusy(`settings:${settingsPlugin.id}`);
    try {
      const response = await api.request(`/admin/plugins/${encodeURIComponent(settingsPlugin.id)}/settings`, {
        method: 'PUT',
        body: JSON.stringify({ settings: values }),
      });
      const pluginName = settingsPlugin.name;
      setSettingsPlugin(null);
      await loadPlugins();
      await alertAction?.({ title: 'Plugin settings saved', message: response.message || `${pluginName} settings were updated.` });
      return '';
    } catch (requestError) {
      return requestError.message || 'The plugin settings could not be saved.';
    } finally {
      setBusy('');
    }
  };

  const plugins = Array.isArray(payload?.plugins) ? payload.plugins : [];
  const normalizedSearch = search.trim().toLowerCase();
  const filteredPlugins = plugins.filter((plugin) => {
    const matchesSearch = !normalizedSearch || [plugin.name, plugin.id, plugin.description, plugin.author, ...(plugin.capabilities || [])].some((value) => String(value || '').toLowerCase().includes(normalizedSearch));
    const matchesStatus = statusFilter === 'All'
      || (statusFilter === 'Enabled' && plugin.enabled)
      || (statusFilter === 'Disabled' && !plugin.enabled && plugin.status === 'disabled')
      || (statusFilter === 'Issues' && ['error', 'incompatible', 'missing'].includes(plugin.status));
    return matchesSearch && matchesStatus;
  });
  const summary = payload?.summary || { installed: 0, enabled: 0, disabled: 0, issues: 0 };
  const environment = payload?.environment || {};
  const canUpload = !loading && environment.zip_available !== false && environment.directory_writable !== false;
  const metrics = [
    { label: 'Installed', value: summary.installed || 0, sub: 'packages', icon: 'plugin', tone: 'blue', points: [] },
    { label: 'Enabled', value: summary.enabled || 0, sub: 'loading now', icon: 'resolved', tone: 'green', points: [] },
    { label: 'Disabled', value: summary.disabled || 0, sub: 'inactive', icon: 'pauseCircle', tone: 'violet', points: [] },
    { label: 'Issues', value: summary.issues || 0, sub: 'need attention', icon: 'warning', tone: summary.issues ? 'red' : 'blue', points: [] },
  ];
  const rows = filteredPlugins.map((plugin) => {
    const rowBusy = Boolean(busy);
    const actions = rowBusy ? [{ label: 'Operation in progress', icon: 'refresh', disabled: true }] : [
      { label: 'View details', icon: 'eye', onClick: () => setDetailsPlugin(plugin) },
      plugin.has_settings && { label: 'Configure', icon: 'settings', onClick: () => setSettingsPlugin(plugin) },
      { divider: true },
      plugin.enabled
        ? { label: 'Disable plugin', icon: 'pauseCircle', onClick: () => runPluginAction(plugin, 'disable') }
        : { label: 'Enable plugin', icon: 'play', onClick: () => runPluginAction(plugin, 'enable') },
      { label: 'Remove plugin', icon: 'trash', danger: true, onClick: () => runPluginAction(plugin, 'remove') },
    ];
    return { id: `plugin-${plugin.id}`, cells: [
      <div className="admin-plugin-cell">
        <span className="admin-plugin-mark"><AdminIcon name="plugin" size={20} /></span>
        <span><strong>{plugin.name}</strong><small>{plugin.id}{plugin.author ? ` · ${plugin.author}` : ''}</small>{plugin.description && <em>{plugin.description}</em>}</span>
      </div>,
      <span className="admin-plugin-version"><strong>{plugin.version}</strong><small>PlayHub {plugin.requirements?.playhub || '-'}</small></span>,
      <span className="admin-plugin-capabilities">{(plugin.capabilities || []).slice(0, 3).map((capability) => <Tag label={capability} tone="slate" key={capability} />)}{(plugin.capabilities || []).length > 3 && <small>+{plugin.capabilities.length - 3}</small>}{!(plugin.capabilities || []).length && <small>Manifest only</small>}</span>,
      <span className="admin-plugin-status"><StatusPill label={pluginStatusLabel(plugin.status)} />{plugin.errors?.[0] && <small title={plugin.errors.join('\n')}>{plugin.errors[0]}</small>}</span>,
      <span className="admin-plugin-updated">{formatPluginDate(plugin.updated_at)}<small>{formatPluginBytes(plugin.size_bytes)}</small></span>,
      <AdminRowActions label={plugin.name} actions={actions} />,
    ] };
  });

  return <>
    <div className="admin-main-stack admin-plugin-page">
      <MetricGrid metrics={metrics} columns={4} />
      <section className="admin-plugin-trust" role="note">
        <AdminIcon name="shield" size={20} />
        <span><strong>Trusted code boundary</strong><small>Only enable packages from sources you trust. Enabled plugins execute with PlayHub application privileges.</small></span>
      </section>
      <section className="admin-plugin-environment" aria-label="Plugin environment">
        <div><span>PlayHub</span><strong>{environment.core_version || '-'}</strong></div>
        <div><span>Plugin API</span><strong>{environment.plugin_api_version || '-'}</strong></div>
        <div><span>PHP</span><strong>{environment.php_version || '-'}</strong></div>
        <div className="admin-plugin-path"><span>Package directory</span><strong title={environment.directory || ''}>{environment.directory || '-'}</strong></div>
      </section>
      {(environment.system_enabled === false || environment.zip_available === false || environment.directory_writable === false) && <div className="admin-plugin-environment-error" role="alert"><AdminIcon name="warning" size={18} />{environment.system_enabled === false ? 'The plugin runtime is disabled by server configuration.' : environment.zip_available === false ? 'The PHP ZIP extension is unavailable.' : 'The plugin package directory is not writable.'}</div>}
      <TablePanel title="Installed plugins" actionButton={busy === 'upload' ? 'Uploading...' : 'Upload Package'} actionIcon="cloud" onAction={canUpload && !busy ? () => packageInputRef.current?.click() : null} flush>
        <input ref={packageInputRef} className="admin-plugin-file-input" type="file" accept=".zip,application/zip" onChange={uploadPackage} />
        <FilterBar search="Search plugins, authors, or capabilities..." value={search} onSearch={setSearch} selects={[{ label: 'Status', value: statusFilter, options: ['All', 'Enabled', 'Disabled', 'Issues'], onChange: setStatusFilter }]} refresh onRefresh={!busy ? loadPlugins : null} reset onReset={() => setStatusFilter('All')} />
        {loading ? <div className="admin-table-loading">Loading plugins...</div> : error ? <div className="admin-plugin-load-error"><AdminIcon name="warning" size={18} />{error}<button type="button" onClick={loadPlugins}>Retry</button></div> : rows.length ? <SimpleTable columns={['Extension', 'Version', 'Capabilities', 'Status', 'Updated', 'Actions']} rows={rows} /> : null}
        {!loading && !error && !plugins.length && <div className="admin-plugin-empty"><AdminIcon name="plugin" size={28} /><strong>No plugins installed</strong><small>Upload a trusted PlayHub plugin ZIP to add an extension.</small></div>}
        {!loading && !error && plugins.length > 0 && !filteredPlugins.length && <div className="admin-plugin-empty compact"><strong>No matching plugins</strong><small>Change the search or status filter.</small></div>}
      </TablePanel>
    </div>
    {settingsPlugin && <PluginSettingsDialog key={`${settingsPlugin.id}-${settingsPlugin.updated_at || ''}`} plugin={settingsPlugin} saving={busy === `settings:${settingsPlugin.id}`} onClose={() => !busy && setSettingsPlugin(null)} onSave={savePluginSettings} />}
    {detailsPlugin && <PluginDetailsDialog plugin={detailsPlugin} onClose={() => setDetailsPlugin(null)} />}
  </>;
}

function PluginSettingsDialog({ plugin, saving = false, onClose, onSave }) {
  const fields = Array.isArray(plugin.settings) ? plugin.settings : [];
  const [values, setValues] = useState(() => Object.fromEntries(fields.map((field) => [field.key, field.type === 'boolean' ? Boolean(field.value) : (field.value ?? '')])));
  const [error, setError] = useState('');

  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape' && !saving) onClose(); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose, saving]);

  const updateValue = (key, value) => setValues((current) => ({ ...current, [key]: value }));
  const submit = async (event) => {
    event.preventDefault();
    if (saving) return;
    setError('');
    const message = await onSave(values);
    if (message) setError(message);
  };

  return <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !saving && onClose()}>
    <form className="admin-plugin-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-plugin-settings-title" onSubmit={submit}>
      <header>
        <span className="admin-confirm-icon"><AdminIcon name="settings" size={22} /></span>
        <span><h2 id="admin-plugin-settings-title">{plugin.name} settings</h2><p>{plugin.id} · {plugin.version}</p></span>
        <button type="button" className="admin-icon-button" aria-label="Close plugin settings" onClick={onClose} disabled={saving}><AdminIcon name="close" size={18} /></button>
      </header>
      <div className="admin-plugin-settings-fields">
        {fields.map((field) => field.type === 'boolean' ? <label className="admin-plugin-boolean-field" key={field.key}>
          <input type="checkbox" checked={Boolean(values[field.key])} onChange={(event) => updateValue(field.key, event.target.checked)} />
          <span><strong>{field.label}</strong>{field.description && <small>{field.description}</small>}</span>
        </label> : <label className="admin-plugin-setting-field" key={field.key}>
          <span>{field.label}{field.required && <em>Required</em>}</span>
          {field.type === 'textarea' ? <textarea value={values[field.key]} maxLength={field.max_length || undefined} placeholder={field.placeholder || ''} required={field.required} onChange={(event) => updateValue(field.key, event.target.value)} />
            : field.type === 'select' ? <AdaptiveSelect value={values[field.key]} required={field.required} onChange={(event) => updateValue(field.key, event.target.value)}><option value="">Select an option</option>{(field.options || []).map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}</AdaptiveSelect>
              : <input type={field.type === 'password' ? 'password' : field.type === 'number' ? 'number' : 'text'} value={values[field.key]} min={field.min ?? undefined} max={field.max ?? undefined} maxLength={field.type === 'number' ? undefined : field.max_length || undefined} step={field.type === 'number' ? 'any' : undefined} placeholder={field.type === 'password' && field.configured ? 'Stored value' : field.placeholder || ''} required={field.required && !(field.type === 'password' && field.configured)} onChange={(event) => updateValue(field.key, event.target.value)} />}
          {field.description && <small>{field.description}</small>}
          {field.type === 'password' && field.configured && <small>The current secret remains unchanged when this field is empty.</small>}
        </label>)}
      </div>
      {error && <div className="admin-plugin-dialog-error" role="alert"><AdminIcon name="warning" size={17} />{error}</div>}
      <footer>
        <button type="button" className="admin-confirm-button cancel" onClick={onClose} disabled={saving}>Cancel</button>
        <button type="submit" className="admin-confirm-button primary" disabled={saving}>{saving ? 'Saving...' : 'Save settings'}</button>
      </footer>
    </form>
  </div>;
}

function PluginDetailsDialog({ plugin, onClose }) {
  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape') onClose(); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose]);

  const dependencies = Object.entries(plugin.dependencies || {});
  return <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
    <section className="admin-plugin-dialog admin-plugin-details-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-plugin-details-title">
      <header>
        <span className="admin-confirm-icon"><AdminIcon name="plugin" size={22} /></span>
        <span><h2 id="admin-plugin-details-title">{plugin.name}</h2><p>{plugin.id} · {plugin.version}</p></span>
        <button type="button" className="admin-icon-button" aria-label="Close plugin details" onClick={onClose}><AdminIcon name="close" size={18} /></button>
      </header>
      <div className="admin-plugin-details-body">
        {plugin.description && <p className="admin-plugin-description">{plugin.description}</p>}
        {!!plugin.errors?.length && <div className="admin-plugin-detail-errors" role="alert"><strong>Plugin issue</strong>{plugin.errors.map((item) => <span key={item}>{item}</span>)}</div>}
        <dl className="admin-plugin-detail-grid">
          <div><dt>Status</dt><dd><StatusPill label={pluginStatusLabel(plugin.status)} /></dd></div>
          <div><dt>Author</dt><dd>{plugin.author || 'Not specified'}</dd></div>
          <div><dt>Installed</dt><dd>{formatPluginDate(plugin.installed_at)}</dd></div>
          <div><dt>Updated</dt><dd>{formatPluginDate(plugin.updated_at)}</dd></div>
          <div><dt>PlayHub requirement</dt><dd>{plugin.requirements?.playhub || '-'}</dd></div>
          <div><dt>PHP requirement</dt><dd>{plugin.requirements?.php || '-'}</dd></div>
          <div><dt>Package size</dt><dd>{formatPluginBytes(plugin.size_bytes)}</dd></div>
          <div><dt>Checksum</dt><dd className="admin-plugin-checksum">{plugin.checksum || '-'}</dd></div>
        </dl>
        {!!plugin.capabilities?.length && <section className="admin-plugin-detail-section"><h3>Capabilities</h3><div>{plugin.capabilities.map((capability) => <Tag label={capability} tone="slate" key={capability} />)}</div></section>}
        <section className="admin-plugin-detail-section"><h3>Dependencies</h3>{dependencies.length ? <div>{dependencies.map(([id, version]) => <Tag label={`${id} ${version}`} tone="slate" key={id} />)}</div> : <p>None</p>}</section>
        {plugin.homepage && <a className="admin-plugin-homepage" href={plugin.homepage} target="_blank" rel="noreferrer"><AdminIcon name="external" size={16} />Plugin homepage</a>}
      </div>
      <footer><button type="button" className="admin-confirm-button primary" onClick={onClose}>Close</button></footer>
    </section>
  </div>;
}

function pluginStatusLabel(status) {
  return ({ enabled: 'Enabled', disabled: 'Disabled', error: 'Error', incompatible: 'Incompatible', missing: 'Files missing' })[status] || 'Unknown';
}

function formatPluginDate(value) {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString();
}

function formatPluginBytes(value) {
  const bytes = Number(value || 0);
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function SitePagesPage({ api = null, confirmAction = null, alertAction = null }) {
  const [payload, setPayload] = useState(null);
  const [locale, setLocale] = useState('');
  const [pageKey, setPageKey] = useState('');
  const [form, setForm] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const pages = Array.isArray(payload?.pages) ? payload.pages : [];
  const activePage = pages.find((page) => page.key === pageKey) || null;
  const activeLanguage = (payload?.languages || []).find((language) => language.locale === locale) || null;
  const comparable = (page) => page ? JSON.stringify({ enabled: Boolean(page.enabled), title: page.title || '', navigation_label: page.navigation_label || '', subtitle: page.subtitle || '', sections: page.sections || [] }) : '';
  const dirty = Boolean(activePage && form && comparable(activePage) !== comparable(form));
  const unpublishedLegalPage = ['terms', 'privacy'].includes(activePage?.key) && !activePage?.customized && Boolean(form?.enabled);

  const editPage = (page) => {
    setPageKey(page?.key || '');
    setForm(page ? {
      enabled: Boolean(page.enabled), title: page.title || '', navigation_label: page.navigation_label || '', subtitle: page.subtitle || '',
      sections: (page.sections || []).map((section) => ({ title: section.title || '', body: section.body || '' })),
    } : null);
  };

  const loadCatalog = useCallback(async (requestedLocale = '', preferredPage = '') => {
    if (!api?.request) return;
    setLoading(true);
    try {
      const query = requestedLocale ? `?locale=${encodeURIComponent(requestedLocale)}` : '';
      const data = await api.request(`/admin/content-pages${query}`);
      const nextPages = Array.isArray(data?.pages) ? data.pages : [];
      setPayload(data || null);
      setLocale(data?.locale || requestedLocale);
      editPage(nextPages.find((page) => page.key === preferredPage) || nextPages[0] || null);
    } catch (error) {
      await alertAction?.({ title: 'Unable to load pages', message: error.message || 'The page catalog could not be loaded.', tone: 'danger' });
    } finally {
      setLoading(false);
    }
  }, [api, alertAction]);

  useEffect(() => { loadCatalog(); }, [loadCatalog]);

  const confirmDiscard = async () => !dirty || Boolean(await confirmAction?.({
    title: 'Discard unsaved page changes?', message: 'Your page content edits will be lost.', confirmLabel: 'Discard changes', tone: 'danger',
  }));
  const selectLocale = async (nextLocale) => {
    if (!nextLocale || nextLocale === locale || !await confirmDiscard()) return;
    await loadCatalog(nextLocale, pageKey);
  };
  const selectPage = async (nextKey) => {
    if (!nextKey || nextKey === pageKey || !await confirmDiscard()) return;
    editPage(pages.find((page) => page.key === nextKey));
  };
  const setValue = (key, value) => setForm((current) => ({ ...current, [key]: value }));
  const setSection = (index, key, value) => setForm((current) => ({ ...current, sections: current.sections.map((section, sectionIndex) => sectionIndex === index ? { ...section, [key]: value } : section) }));
  const addSection = () => setForm((current) => ({ ...current, sections: [...current.sections, { title: '', body: '' }] }));
  const removeSection = (index) => setForm((current) => ({ ...current, sections: current.sections.filter((_, sectionIndex) => sectionIndex !== index) }));

  const savePage = async () => {
    if (!api?.request || !activePage || !form || saving || (!dirty && !unpublishedLegalPage)) return;
    setSaving(true);
    try {
      const response = await api.request(`/admin/content-pages/${encodeURIComponent(activePage.key)}`, {
        method: 'PUT', body: JSON.stringify({ locale, ...form }),
      });
      if (response?.page) {
        setPayload((current) => ({ ...current, pages: (current?.pages || []).map((page) => page.key === response.page.key ? response.page : page) }));
        editPage(response.page);
      }
      await alertAction?.({ title: 'Page updated', message: response?.message || 'The public page was updated.' });
    } catch (error) {
      await alertAction?.({ title: 'Unable to save page', message: error.message || 'The page could not be saved.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  const resetPage = async () => {
    if (!api?.request || !activePage?.customized || saving) return;
    const confirmed = await confirmAction?.({ title: `Reset ${activePage.title}?`, message: `The ${activeLanguage?.native_label || locale} override will be removed and fallback content will be restored. Visibility will not change.`, confirmLabel: 'Reset page', tone: 'danger' });
    if (!confirmed) return;
    setSaving(true);
    try {
      const response = await api.request(`/admin/content-pages/${encodeURIComponent(activePage.key)}`, { method: 'DELETE', body: JSON.stringify({ locale }) });
      if (response?.page) {
        setPayload((current) => ({ ...current, pages: (current?.pages || []).map((page) => page.key === response.page.key ? response.page : page) }));
        editPage(response.page);
      }
      await alertAction?.({ title: 'Page reset', message: response?.message || 'Fallback content was restored.' });
    } catch (error) {
      await alertAction?.({ title: 'Unable to reset page', message: error.message || 'The page could not be reset.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  const complete = Boolean(form?.title.trim() && form?.navigation_label.trim() && form?.subtitle.trim() && form?.sections.length && form.sections.every((section) => section.title.trim() && section.body.trim()));

  return <div className="admin-main-stack admin-site-pages">
    <TablePanel title="Public page management" flush>
      <div className="admin-site-pages-toolbar">
        <label><span>Language</span><AdaptiveSelect searchable value={locale} disabled={loading || saving} onChange={(event) => selectLocale(event.target.value)}>{(payload?.languages || []).map((language) => <option value={language.locale} key={language.locale}>{language.native_label} ({language.locale}){language.default ? ' - Default' : language.enabled === false ? ' - Disabled language' : ''}</option>)}</AdaptiveSelect></label>
        <label><span>Page</span><AdaptiveSelect value={pageKey} disabled={loading || saving} onChange={(event) => selectPage(event.target.value)}>{pages.map((page) => <option value={page.key} key={page.key}>{page.navigation_label} - {page.route}</option>)}</AdaptiveSelect></label>
        <div className="admin-site-pages-actions"><button type="button" className="admin-outline-action" onClick={() => activePage?.route && window.open(activePage.route, '_blank', 'noopener,noreferrer')} disabled={!activePage}><AdminIcon name="external" size={16}/>Preview</button><button type="button" className="admin-outline-action" onClick={resetPage} disabled={!activePage?.customized || saving}><AdminIcon name="refresh" size={16}/>Reset</button><button type="button" className="admin-action-button primary" onClick={savePage} disabled={(!dirty && !unpublishedLegalPage) || !complete || saving}><AdminIcon name="resolved" size={16}/>{saving ? 'Saving...' : unpublishedLegalPage ? 'Publish legal page' : 'Save page'}</button></div>
      </div>
      {loading && <div className="admin-table-loading">Loading public pages...</div>}
      {!loading && !activePage && <div className="admin-language-empty">No managed pages are available.</div>}
      {!loading && activePage && form && <div className="admin-site-page-layout">
        <aside className="admin-site-page-list" aria-label="Managed pages">{pages.map((page) => <button type="button" key={page.key} className={page.key === pageKey ? 'active' : ''} onClick={() => selectPage(page.key)}><span><strong>{page.navigation_label}</strong><small>{page.route}</small></span><em className={page.enabled ? 'published' : ''}>{page.enabled ? 'Published' : 'Hidden'}</em></button>)}</aside>
        <section className="admin-site-page-editor" dir={activeLanguage?.direction || 'ltr'}>
          <header><div><h2>{activePage.navigation_label}</h2><p>Changes update the public page, footer navigation, search metadata, and sitemap.</p></div><em className={activePage.customized ? 'customized' : ''}>{activePage.customized ? `${activeLanguage?.native_label || locale} override` : activePage.source_locale ? `Fallback: ${activePage.source_locale}` : 'Built-in fallback'}</em></header>
          <label className="admin-site-page-status"><input type="checkbox" checked={form.enabled} onChange={(event) => setValue('enabled', event.target.checked)}/><span><strong>Publish this page</strong><small>Hidden pages are removed from the footer and sitemap and marked noindex.</small></span></label>
          <div className="admin-site-page-field-grid"><label className="admin-edit-field"><span>Page title</span><input value={form.title} maxLength={160} onChange={(event) => setValue('title', event.target.value)} required/></label><label className="admin-edit-field"><span>Footer label</span><input value={form.navigation_label} maxLength={80} onChange={(event) => setValue('navigation_label', event.target.value)} required/></label></div>
          <label className="admin-edit-field"><span>Page summary / SEO description</span><textarea rows={3} value={form.subtitle} maxLength={500} onChange={(event) => setValue('subtitle', event.target.value)} required/><small>{form.subtitle.length} / 500</small></label>
          <div className="admin-site-page-section-head"><span><strong>Content sections</strong><small>Use blank lines to separate paragraphs. New lines are preserved on the website.</small></span><button type="button" className="admin-outline-action" onClick={addSection} disabled={form.sections.length >= 30}><AdminIcon name="plus" size={16}/>Add section</button></div>
          <div className="admin-site-page-sections">{form.sections.map((section, index) => <section key={`${index}-${activePage.key}`}><header><strong>Section {index + 1}</strong><button type="button" onClick={() => removeSection(index)} disabled={form.sections.length <= 1} aria-label={`Remove section ${index + 1}`} title="Remove section"><AdminIcon name="trash" size={16}/></button></header><label className="admin-edit-field"><span>Heading</span><input value={section.title} maxLength={180} onChange={(event) => setSection(index, 'title', event.target.value)} required/></label><label className="admin-edit-field"><span>Content</span><textarea rows={6} value={section.body} maxLength={20000} onChange={(event) => setSection(index, 'body', event.target.value)} required/></label></section>)}</div>
        </section>
      </div>}
    </TablePanel>
  </div>;
}

function EmailTemplatesPage({ api = null, confirmAction = null, alertAction = null }) {
  const [payload, setPayload] = useState(null);
  const [locale, setLocale] = useState('');
  const [templateKey, setTemplateKey] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const subjectRef = useRef(null);
  const bodyRef = useRef(null);
  const activeEditorRef = useRef('body');

  const templates = Array.isArray(payload?.templates) ? payload.templates : [];
  const activeTemplate = templates.find((template) => template.key === templateKey) || null;
  const activeLanguage = (payload?.languages || []).find((language) => language.locale === locale) || null;
  const dirty = Boolean(activeTemplate && (subject !== activeTemplate.subject || body !== activeTemplate.body));

  const applyTemplate = (template) => {
    setTemplateKey(template?.key || '');
    setSubject(template?.subject || '');
    setBody(template?.body || '');
  };

  const loadCatalog = useCallback(async (requestedLocale = '', preferredTemplate = '') => {
    if (!api?.request) return;
    setLoading(true);
    try {
      const query = requestedLocale ? `?locale=${encodeURIComponent(requestedLocale)}` : '';
      const data = await api.request(`/admin/email-templates${query}`);
      const nextTemplates = Array.isArray(data?.templates) ? data.templates : [];
      const nextTemplate = nextTemplates.find((template) => template.key === preferredTemplate) || nextTemplates[0] || null;
      setPayload(data || null);
      setLocale(data?.locale || requestedLocale);
      applyTemplate(nextTemplate);
    } catch (error) {
      await alertAction?.({ title: 'Unable to load email templates', message: error.message || 'The email template catalog could not be loaded.', tone: 'danger' });
    } finally {
      setLoading(false);
    }
  }, [api, alertAction]);

  useEffect(() => { loadCatalog(); }, [loadCatalog]);

  const confirmDiscard = async () => {
    if (!dirty) return true;
    return Boolean(await confirmAction?.({
      title: 'Discard unsaved email changes?',
      message: 'Your subject and body edits will be lost.',
      confirmLabel: 'Discard changes',
      tone: 'danger',
    }));
  };

  const selectLocale = async (nextLocale) => {
    if (!nextLocale || nextLocale === locale || !await confirmDiscard()) return;
    await loadCatalog(nextLocale, templateKey);
  };

  const selectTemplate = async (nextKey) => {
    if (!nextKey || nextKey === templateKey || !await confirmDiscard()) return;
    applyTemplate(templates.find((template) => template.key === nextKey));
  };

  const saveTemplate = async () => {
    if (!api?.request || !activeTemplate || !dirty || saving) return;
    setSaving(true);
    try {
      const response = await api.request(`/admin/email-templates/${encodeURIComponent(activeTemplate.key)}`, {
        method: 'PUT',
        body: JSON.stringify({ locale, subject, body }),
      });
      const updated = response?.template;
      if (updated) {
        setPayload((current) => ({ ...current, templates: (current?.templates || []).map((template) => template.key === updated.key ? updated : template) }));
        setSubject(updated.subject || '');
        setBody(updated.body || '');
      }
      await alertAction?.({ title: 'Email template updated', message: response?.message || 'The localized email template was saved.' });
    } catch (error) {
      await alertAction?.({ title: 'Unable to save email template', message: error.message || 'The email template could not be saved.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  const resetTemplate = async () => {
    if (!api?.request || !activeTemplate?.customized || saving) return;
    const confirmed = await confirmAction?.({
      title: `Reset ${activeTemplate.name}?`,
      message: `The ${activeLanguage?.native_label || locale} override will be removed and the fallback template will be used.`,
      confirmLabel: 'Reset template',
      tone: 'danger',
    });
    if (!confirmed) return;
    setSaving(true);
    try {
      const response = await api.request(`/admin/email-templates/${encodeURIComponent(activeTemplate.key)}`, {
        method: 'DELETE',
        body: JSON.stringify({ locale }),
      });
      const updated = response?.template;
      if (updated) {
        setPayload((current) => ({ ...current, templates: (current?.templates || []).map((template) => template.key === updated.key ? updated : template) }));
        setSubject(updated.subject || '');
        setBody(updated.body || '');
      }
      await alertAction?.({ title: 'Email template reset', message: response?.message || 'The language override was removed.' });
    } catch (error) {
      await alertAction?.({ title: 'Unable to reset email template', message: error.message || 'The email template could not be reset.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  const insertPlaceholder = (token) => {
    const editingSubject = activeEditorRef.current === 'subject';
    const element = editingSubject ? subjectRef.current : bodyRef.current;
    const value = editingSubject ? subject : body;
    const start = Number.isInteger(element?.selectionStart) ? element.selectionStart : value.length;
    const end = Number.isInteger(element?.selectionEnd) ? element.selectionEnd : start;
    const next = `${value.slice(0, start)}${token}${value.slice(end)}`;
    if (editingSubject) setSubject(next);
    else setBody(next);
    window.requestAnimationFrame(() => {
      element?.focus();
      element?.setSelectionRange?.(start + token.length, start + token.length);
    });
  };

  const examples = Object.fromEntries((activeTemplate?.placeholders || []).map((placeholder) => [placeholder.token, placeholder.example]));
  const previewValue = (value) => String(value || '').replace(/\{[A-Z][A-Z0-9_]*\}/g, (token) => examples[token] ?? token);

  return <div className="admin-main-stack admin-email-template-page">
    <TablePanel title="Localized email templates" flush>
      <div className="admin-email-template-toolbar">
        <label><span>Language</span><AdaptiveSelect searchable value={locale} disabled={loading || saving} onChange={(event) => selectLocale(event.target.value)} aria-label="Email template language">
          {(payload?.languages || []).map((language) => <option value={language.locale} key={language.locale}>{language.native_label} - {language.label} ({language.locale}){language.default ? ' - Default' : language.enabled === false ? ' - Disabled' : ''}</option>)}
        </AdaptiveSelect></label>
        <label><span>Email template</span><AdaptiveSelect value={templateKey} disabled={loading || saving} onChange={(event) => selectTemplate(event.target.value)} aria-label="Email template">
          {templates.map((template) => <option value={template.key} key={template.key}>{template.name}</option>)}
        </AdaptiveSelect></label>
        <div className="admin-email-template-actions">
          <button type="button" className="admin-outline-action" onClick={resetTemplate} disabled={!activeTemplate?.customized || loading || saving}><AdminIcon name="refresh" size={16} />Reset</button>
          <button type="button" className="admin-action-button primary" onClick={saveTemplate} disabled={!dirty || loading || saving}><AdminIcon name="resolved" size={16} />{saving ? 'Saving...' : 'Save template'}</button>
        </div>
      </div>

      {loading && <div className="admin-table-loading">Loading email templates...</div>}
      {!loading && !activeTemplate && <div className="admin-language-empty">No email templates are available.</div>}
      {!loading && activeTemplate && <div className="admin-email-template-layout">
        <section className="admin-email-template-editor">
          <header>
            <span><AdminIcon name="mail" size={19} /></span>
            <div><h2>{activeTemplate.name}</h2><p>{activeTemplate.description}</p></div>
            <em className={activeTemplate.customized ? 'customized' : ''}>{activeTemplate.customized ? `${activeLanguage?.native_label || locale} override` : activeTemplate.source_locale ? `Fallback: ${activeTemplate.source_locale}` : 'Built-in fallback'}</em>
          </header>
          <label className="admin-email-subject-field"><span>Subject</span><input ref={subjectRef} dir={activeLanguage?.direction || 'ltr'} value={subject} maxLength={255} onFocus={() => { activeEditorRef.current = 'subject'; }} onChange={(event) => setSubject(event.target.value.replace(/[\r\n]/g, ''))} /></label>
          <label className="admin-email-body-field"><span>Message body</span><textarea ref={bodyRef} dir={activeLanguage?.direction || 'ltr'} value={body} maxLength={20000} onFocus={() => { activeEditorRef.current = 'body'; }} onChange={(event) => setBody(event.target.value)} /></label>
          <div className="admin-email-template-preview">
            <header><span>Preview with sample data</span><small>Branded colors and layout are applied when sent</small></header>
            <strong dir={activeLanguage?.direction || 'ltr'}>{previewValue(subject) || 'Email subject'}</strong>
            <pre dir={activeLanguage?.direction || 'ltr'}>{previewValue(body) || 'Email message'}</pre>
          </div>
        </section>
        <aside className="admin-email-placeholder-guide">
          <header><h2>Available placeholders</h2><span>{activeTemplate.placeholders?.length || 0}</span></header>
          <div>{(activeTemplate.placeholders || []).map((placeholder) => <button type="button" key={placeholder.token} onClick={() => insertPlaceholder(placeholder.token)} title={`Insert ${placeholder.token}`}>
            <code data-i18n-skip>{placeholder.token}</code>
            <span><strong>{placeholder.name}</strong><small>{placeholder.description}</small><em>{placeholder.usage}</em></span>
            <AdminIcon name="edit" size={15} />
          </button>)}</div>
        </aside>
      </div>}
    </TablePanel>
  </div>;
}

function LanguagesPage({ api = null, confirmAction = null, alertAction = null }) {
  const tabs = ['Translations', 'Plural forms', 'Patterns', 'Metadata'];
  const [languages, setLanguages] = useState([]);
  const [selectedLocale, setSelectedLocale] = useState('');
  const [payload, setPayload] = useState(null);
  const [activeTab, setActiveTab] = useState(tabs[0]);
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [page, setPage] = useState(1);
  const [loadingList, setLoadingList] = useState(true);
  const [loadingLanguage, setLoadingLanguage] = useState(false);
  const [saving, setSaving] = useState(false);
  const [defaultLanguage, setDefaultLanguage] = useState('');
  const [pendingDefaultLanguage, setPendingDefaultLanguage] = useState('');
  const [savingDefaultLanguage, setSavingDefaultLanguage] = useState(false);
  const [updatingLanguageStatus, setUpdatingLanguageStatus] = useState(false);
  const [changes, setChanges] = useState({});
  const languageRequestRef = useRef(0);

  const loadLanguages = useCallback(async () => {
    if (!api?.request) return;
    setLoadingList(true);
    try {
      const data = await api.request('/admin/languages');
      const next = Array.isArray(data?.languages) ? data.languages : [];
      const configuredDefault = data?.default_language || next.find((language) => language.default)?.locale || next.find((language) => language.locale === 'en')?.locale || next[0]?.locale || '';
      setLanguages(next);
      setDefaultLanguage(configuredDefault);
      setPendingDefaultLanguage((current) => current && next.some((language) => language.locale === current) ? current : configuredDefault);
      setSelectedLocale((current) => {
        if (current && next.some((language) => language.locale === current)) return current;
        return configuredDefault;
      });
    } catch (error) {
      await alertAction?.({ title: 'Unable to load languages', message: error.message || 'The language catalog could not be loaded.', tone: 'danger' });
    } finally {
      setLoadingList(false);
    }
  }, [api, alertAction]);

  const loadLanguage = useCallback(async () => {
    if (!api?.request || !selectedLocale) return;
    const requestId = ++languageRequestRef.current;
    setLoadingLanguage(true);
    try {
      const query = new URLSearchParams({ page: String(page), per_page: '100' });
      if (debouncedSearch) query.set('search', debouncedSearch);
      const data = await api.request(`/admin/languages/${encodeURIComponent(selectedLocale)}?${query}`);
      if (requestId === languageRequestRef.current) setPayload(data || null);
    } catch (error) {
      if (requestId === languageRequestRef.current) {
        await alertAction?.({ title: 'Unable to load language', message: error.message || 'The selected language could not be loaded.', tone: 'danger' });
      }
    } finally {
      if (requestId === languageRequestRef.current) setLoadingLanguage(false);
    }
  }, [api, selectedLocale, page, debouncedSearch, alertAction]);

  useEffect(() => { loadLanguages(); }, [loadLanguages]);
  useEffect(() => { loadLanguage(); }, [loadLanguage]);
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setPage(1);
      setDebouncedSearch(search.trim());
    }, 250);
    return () => window.clearTimeout(timer);
  }, [search]);

  const changeId = (section, key, form = '') => `${section}\u0000${key}\u0000${form}`;
  const changedValue = (section, key, original, form = '') => changes[changeId(section, key, form)]?.value ?? original;
  const updateValue = (section, key, value, original, form = '') => {
    const id = changeId(section, key, form);
    setChanges((current) => {
      const next = { ...current };
      if (value === original) delete next[id];
      else next[id] = { section, key, value, ...(section === 'plural' ? { form } : {}) };
      return next;
    });
  };
  const changeCount = Object.keys(changes).length;

  const confirmDiscard = async () => {
    if (!changeCount) return true;
    return Boolean(await confirmAction?.({
      title: 'Discard unsaved language changes?',
      message: 'Your edits will be lost when you switch or reload the language.',
      confirmLabel: 'Discard changes',
      tone: 'danger',
    }));
  };

  const selectLanguage = async (locale) => {
    if (!locale || locale === selectedLocale || !await confirmDiscard()) return;
    languageRequestRef.current += 1;
    setChanges({});
    setPayload(null);
    setSearch('');
    setDebouncedSearch('');
    setPage(1);
    setSelectedLocale(locale);
  };

  const reloadLanguage = async () => {
    if (!await confirmDiscard()) return;
    setChanges({});
    await loadLanguage();
  };

  const saveLanguage = async () => {
    if (!api?.request || !payload?.checksum || !changeCount || saving) return;
    setSaving(true);
    let checksum = payload.checksum;
    try {
      const pending = Object.entries(changes);
      let updated = 0;
      for (let offset = 0; offset < pending.length; offset += 500) {
        const batch = pending.slice(offset, offset + 500);
        const response = await api.request(`/admin/languages/${encodeURIComponent(selectedLocale)}`, {
          method: 'PUT',
          body: JSON.stringify({ checksum, changes: batch.map(([, change]) => change) }),
        });
        checksum = response?.checksum || checksum;
        updated += Number(response?.updated || 0);
        const savedIds = new Set(batch.map(([id]) => id));
        setChanges((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !savedIds.has(id))));
        setPayload((current) => current ? { ...current, checksum, language: { ...current.language, updated_at: response?.updated_at || current.language?.updated_at } } : current);
      }
      await loadLanguages();
      await loadLanguage();
      await alertAction?.({ title: 'Language updated', message: `${updated} language value${updated === 1 ? '' : 's'} updated.` });
    } catch (error) {
      await alertAction?.({ title: 'Unable to save language', message: error.message || 'The language file could not be saved.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  const saveDefaultLanguage = async () => {
    if (!api?.request || !pendingDefaultLanguage || pendingDefaultLanguage === defaultLanguage || savingDefaultLanguage) return;
    setSavingDefaultLanguage(true);
    try {
      const response = await api.request('/admin/languages/default', {
        method: 'PUT',
        body: JSON.stringify({ locale: pendingDefaultLanguage }),
      });
      const locale = response?.default_language || pendingDefaultLanguage;
      setDefaultLanguage(locale);
      setPendingDefaultLanguage(locale);
      setLanguages((current) => current.map((item) => ({ ...item, default: item.locale === locale })));
      setPayload((current) => current?.language ? { ...current, language: { ...current.language, default: current.language.locale === locale } } : current);
      await alertAction?.({ title: 'Default language updated', message: response?.message || 'The website default language was updated.' });
    } catch (error) {
      await alertAction?.({ title: 'Unable to update default language', message: error.message || 'The website default language could not be updated.', tone: 'danger' });
    } finally {
      setSavingDefaultLanguage(false);
    }
  };

  const updateLanguageStatus = async (enabled) => {
    const selected = languages.find((item) => item.locale === selectedLocale);
    const currentlyEnabled = selected?.enabled !== false;
    if (!api?.request || !selected || updatingLanguageStatus || currentlyEnabled === enabled) return;
    if (!enabled) {
      const confirmed = await confirmAction?.({
        title: `Disable ${selected.native_label || selected.label}?`,
        message: 'It will be removed from visitor language choices and its public translation endpoint will be unavailable.',
        confirmLabel: 'Disable language',
      });
      if (!confirmed) return;
    }

    setUpdatingLanguageStatus(true);
    try {
      const response = await api.request(`/admin/languages/${encodeURIComponent(selected.locale)}/status`, {
        method: 'PUT',
        body: JSON.stringify({ enabled }),
      });
      const nextEnabled = response?.enabled ?? enabled;
      setLanguages((current) => current.map((item) => item.locale === selected.locale ? { ...item, enabled: nextEnabled } : item));
      setPayload((current) => current?.language?.locale === selected.locale ? { ...current, language: { ...current.language, enabled: nextEnabled } } : current);
      if (!nextEnabled) setPendingDefaultLanguage((current) => current === selected.locale ? defaultLanguage : current);
      await alertAction?.({ title: enabled ? 'Language enabled' : 'Language disabled', message: response?.message || `The website language was ${enabled ? 'enabled' : 'disabled'}.` });
    } catch (error) {
      await alertAction?.({ title: 'Unable to update language', message: error.message || 'The website language status could not be updated.', tone: 'danger' });
    } finally {
      setUpdatingLanguageStatus(false);
    }
  };

  const language = payload?.language || null;
  const selectedLanguage = languages.find((item) => item.locale === selectedLocale) || null;
  const enabledLanguageCount = languages.filter((item) => item.enabled !== false).length;
  const statusLocked = selectedLanguage?.enabled !== false && (selectedLanguage?.default || enabledLanguageCount <= 1);
  const meta = payload?.meta || { current_page: page, last_page: 1, total: 0, from: 0, to: 0 };
  const tabCounts = {
    Translations: payload?.stats?.entries || 0,
    'Plural forms': payload?.stats?.plural_forms || 0,
    Patterns: payload?.stats?.patterns || 0,
    Metadata: 4,
  };

  return <div className="admin-main-stack admin-language-page">
    <TablePanel title="Language catalog" flush>
      <div className="admin-language-toolbar">
        <label className="admin-language-select">
          <span>Select language</span>
          <AdaptiveSelect searchable value={selectedLocale} disabled={loadingList || saving || !languages.length} onChange={(event) => selectLanguage(event.target.value)} aria-label="Select language">
            {languages.map((item) => <option value={item.locale} key={item.locale}>{item.native_label} - {item.label} ({item.locale}){item.locale === defaultLanguage ? ' - Default' : item.enabled === false ? ' - Disabled' : ''}</option>)}
          </AdaptiveSelect>
        </label>
        {selectedLanguage && <div className="admin-language-availability" title={statusLocked ? 'Set another default language before disabling this language.' : ''}>
          <EditableToggleField label="Available on website" checked={selectedLanguage.enabled !== false} onChange={updateLanguageStatus} disabled={loadingList || loadingLanguage || saving || updatingLanguageStatus || statusLocked} />
        </div>}
        <div className="admin-language-actions">
          <button type="button" className="admin-outline-action" onClick={reloadLanguage} disabled={!selectedLocale || loadingLanguage || saving}><AdminIcon name="refresh" size={16} />Reload</button>
          <button type="button" className="admin-action-button primary" onClick={saveLanguage} disabled={!changeCount || loadingLanguage || saving || language?.writable === false}><AdminIcon name="resolved" size={16} />{saving ? 'Saving...' : 'Save changes'}</button>
        </div>
      </div>

      {!loadingList && !!languages.length && <div className="admin-language-default">
        <span className="admin-language-default-copy">
          <span><AdminIcon name="globe" size={18} /></span>
          <span><strong>Default website language</strong><small>Fallback for visitors without a saved preference</small></span>
        </span>
        <label>
          <span className="sr-only">Default website language</span>
          <AdaptiveSelect searchable value={pendingDefaultLanguage} disabled={savingDefaultLanguage} onChange={(event) => setPendingDefaultLanguage(event.target.value)} aria-label="Default website language">
            {languages.filter((item) => item.enabled !== false).map((item) => <option value={item.locale} key={item.locale}>{item.native_label} - {item.label} ({item.locale})</option>)}
          </AdaptiveSelect>
        </label>
        <button type="button" className="admin-action-button primary" onClick={saveDefaultLanguage} disabled={!pendingDefaultLanguage || pendingDefaultLanguage === defaultLanguage || savingDefaultLanguage}>
          <AdminIcon name="resolved" size={16} />{savingDefaultLanguage ? 'Saving...' : pendingDefaultLanguage === defaultLanguage ? 'Default set' : 'Save default'}
        </button>
      </div>}

      {loadingList && <div className="admin-table-loading">Loading languages...</div>}
      {!loadingList && !languages.length && <div className="admin-language-empty">No language files were found.</div>}
      {language && <>
        {language.writable === false && <div className="admin-language-permission-error" role="alert"><AdminIcon name="warning" size={17} />The selected language directory is read-only. Update its server permissions before editing.</div>}
        <div className="admin-language-summary">
          <div><span>Language</span><strong>{language.native_label}</strong><small>{language.label} ({language.locale})</small></div>
          <div><span>Entries</span><strong>{payload.stats?.entries || 0}</strong><small>{payload.stats?.empty || 0} empty values</small></div>
          <div><span>Source matches</span><strong>{payload.stats?.source_matches || 0}</strong><small>{language.direction === 'rtl' ? 'Right to left' : 'Left to right'}</small></div>
          <div><span>Modified</span><strong>{language.updated_at ? new Date(language.updated_at).toLocaleDateString() : '-'}</strong><small>{language.file}</small></div>
        </div>
        <Tabs tabs={tabs.map((tab) => [tab, tabCounts[tab]])} active={activeTab} onChange={setActiveTab} />
        {activeTab === 'Translations' && <FilterBar search="Search translation keys or values..." value={search} onSearch={setSearch} reset onReset={() => setSearch('')} />}
        {loadingLanguage ? <div className="admin-table-loading">Loading language values...</div> : <div className="admin-language-editor" dir="ltr">
          {activeTab === 'Translations' && <>
            {(payload.entries || []).map((entry) => <label className="admin-language-row" key={entry.key}>
              <span><small>Translation key</small><code data-i18n-skip>{entry.key}</code></span>
              <span><small>Translation</small><textarea dir={language.direction} readOnly={language.writable === false} value={changedValue('translation', entry.key, entry.value)} onChange={(event) => updateValue('translation', entry.key, event.target.value, entry.value)} /></span>
            </label>)}
            {!(payload.entries || []).length && <div className="admin-language-empty">No translations found.</div>}
          </>}
          {activeTab === 'Plural forms' && <>
            {(payload.plurals || []).map((entry) => <label className="admin-language-row" key={`${entry.key}-${entry.form}`}>
              <span><small>Plural key / Form {entry.form}</small><code data-i18n-skip>{entry.key}</code></span>
              <span><small>Translation</small><textarea dir={language.direction} readOnly={language.writable === false} value={changedValue('plural', entry.key, entry.value, entry.form)} onChange={(event) => updateValue('plural', entry.key, event.target.value, entry.value, entry.form)} /></span>
            </label>)}
            {!(payload.plurals || []).length && <div className="admin-language-empty">No plural forms are configured for this language.</div>}
          </>}
          {activeTab === 'Patterns' && <>
            {(payload.patterns || []).map((entry) => <label className="admin-language-row" key={entry.key}>
              <span><small>Pattern</small><code data-i18n-skip>{entry.key}</code></span>
              <span><small>Value</small><textarea dir={language.direction} readOnly={language.writable === false} value={changedValue('pattern', entry.key, entry.value)} onChange={(event) => updateValue('pattern', entry.key, event.target.value, entry.value)} /></span>
            </label>)}
            {!(payload.patterns || []).length && <div className="admin-language-empty">No patterns are configured for this language.</div>}
          </>}
          {activeTab === 'Metadata' && <div className="admin-language-metadata">
            <label><span>Language code</span><input value={language.code || ''} readOnly /></label>
            <label><span>File</span><input value={language.file || ''} readOnly /></label>
            <label><span>Display name</span><input readOnly={language.writable === false} value={changedValue('meta', 'label', language.label || '')} onChange={(event) => updateValue('meta', 'label', event.target.value, language.label || '')} /></label>
            <label><span>Native name</span><input readOnly={language.writable === false} value={changedValue('meta', 'native_label', language.native_label || '')} onChange={(event) => updateValue('meta', 'native_label', event.target.value, language.native_label || '')} /></label>
            <label><span>Direction</span><AdaptiveSelect disabled={language.writable === false} value={changedValue('meta', 'direction', language.direction || 'ltr')} onChange={(event) => updateValue('meta', 'direction', event.target.value, language.direction || 'ltr')}><option value="ltr">Left to right</option><option value="rtl">Right to left</option></AdaptiveSelect></label>
          </div>}
        </div>}
        {activeTab === 'Translations' && !loadingLanguage && <TableFooter text={`Showing ${meta.from || 0} to ${meta.to || 0} of ${meta.total || 0} translations`} pages={adminPaginationPages(meta.current_page || page, meta.last_page || 1)} activePage={meta.current_page || page} lastPage={meta.last_page || 1} onPageChange={setPage} />}
      </>}
      {!!changeCount && <div className="admin-language-savebar" role="status"><span><strong>{changeCount}</strong> unsaved change{changeCount === 1 ? '' : 's'}</span><button type="button" className="admin-action-button primary compact" onClick={saveLanguage} disabled={saving || language?.writable === false}>{saving ? 'Saving...' : 'Save changes'}</button></div>}
    </TablePanel>
  </div>;
}

function GiftsPage({ api = null, confirmAction = null, alertAction = null }) {
  const [gifts, setGifts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editorGift, setEditorGift] = useState(null);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });

  const loadGifts = useCallback(async (nextPage = 1) => {
    if (!api?.request) return;
    setLoading(true);
    try {
      const data = await api.request(`/admin/gifts?page=${nextPage}`);
      setGifts(data.gifts || []);
      setMeta(data.meta || { current_page: nextPage, last_page: nextPage, total: data.gifts?.length || 0, from: data.gifts?.length ? 1 : 0, to: data.gifts?.length || 0 });
    } catch (error) {
      await alertAction?.({ title: 'Unable to load gifts', message: error.message || 'The gift catalog could not be loaded.', tone: 'danger' });
    } finally {
      setLoading(false);
    }
  }, [api, alertAction]);

  useEffect(() => { loadGifts(page); }, [loadGifts, page]);

  const saveGift = async (formData) => {
    if (saving) return;
    setSaving(true);
    try {
      const path = editorGift?.id ? `/admin/gifts/${editorGift.id}` : '/admin/gifts';
      await api.request(path, { method: 'POST', body: formData });
      const nextPage = editorGift?.id ? page : 1;
      setEditorGift(null);
      if (nextPage !== page) setPage(nextPage);
      else await loadGifts(nextPage);
    } catch (error) {
      await alertAction?.({ title: 'Unable to save gift', message: error.message || 'The gift could not be saved.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  const deleteGift = async (gift) => {
    const confirmed = await confirmAction?.({
      title: 'Delete gift?',
      message: `Delete "${gift.name}" and permanently remove its WebP image from the server?`,
      confirmLabel: 'Delete gift',
      busyLabel: 'Deleting...',
      icon: 'trash',
      danger: true,
    });
    if (!confirmed) return;
    setDeletingId(gift.id);
    try {
      await api.request(`/admin/gifts/${gift.id}`, { method: 'DELETE' });
      const nextPage = gifts.length === 1 && page > 1 ? page - 1 : page;
      if (nextPage !== page) setPage(nextPage);
      else await loadGifts(nextPage);
    } catch (error) {
      await alertAction?.({ title: 'Unable to delete gift', message: error.message || 'The gift could not be deleted.', tone: 'danger' });
    } finally {
      setDeletingId(null);
    }
  };

  const rows = gifts.map((gift) => ({ id: `gift-${gift.id}`, cells: [
    <span className="admin-gift-cell"><img src={gift.image_url} alt=""/><span><strong>{gift.name}</strong><small>/{gift.slug}</small></span></span>,
    <strong>{gift.currency} {gift.price}</strong>,
    <StatusPill label={gift.enabled ? 'Enabled' : 'Disabled'} />,
    gift.sort_order,
    gift.updated_at ? new Date(gift.updated_at).toLocaleDateString() : '',
    <AdminRowActions label={gift.name} actions={[
      { label: 'Edit gift', icon: 'edit', onClick: () => setEditorGift(gift) },
      { label: deletingId === gift.id ? 'Deleting...' : 'Delete gift', icon: 'trash', danger: true, onClick: () => deleteGift(gift) },
    ]}/>,
  ] }));

  return <>
    <div className="admin-main-stack">
      <TablePanel title="Gift catalog" actionButton="Add Gift" actionIcon="gift" onAction={() => setEditorGift({})} flush>
        {loading ? <div className="admin-table-loading">Loading gifts...</div> : <><SimpleTable columns={['Gift', 'Price', 'Status', 'Order', 'Updated', 'Actions']} rows={rows}/><TableFooter text={`Showing ${meta.from || 0} to ${meta.to || 0} of ${meta.total || 0} gifts`} pages={adminPaginationPages(meta.current_page || page, meta.last_page || 1)} activePage={meta.current_page || page} lastPage={meta.last_page || 1} onPageChange={setPage}/></>} 
      </TablePanel>
    </div>
    {editorGift && <GiftEditorDialog gift={editorGift} saving={saving} onClose={() => !saving && setEditorGift(null)} onSave={saveGift}/>} 
  </>;
}

function GiftEditorDialog({ gift, saving, onClose, onSave }) {
  const editing = Boolean(gift?.id);
  const [name, setName] = useState(gift?.name || '');
  const [price, setPrice] = useState(gift?.price || '1.00');
  const [sortOrder, setSortOrder] = useState(gift?.sort_order ?? 0);
  const [enabled, setEnabled] = useState(gift?.enabled ?? true);
  const [imageFile, setImageFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState(gift?.image_url || '');

  useEffect(() => {
    if (!imageFile) return undefined;
    const url = URL.createObjectURL(imageFile);
    setPreviewUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [imageFile]);

  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape' && !saving) onClose(); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose, saving]);

  const submit = (event) => {
    event.preventDefault();
    if (!editing && !imageFile) return;
    const formData = new FormData();
    formData.append('name', name.trim());
    formData.append('price', price);
    formData.append('sort_order', String(sortOrder || 0));
    formData.append('enabled', enabled ? '1' : '0');
    if (imageFile) formData.append('image', imageFile);
    onSave(formData);
  };

  return <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !saving && onClose()}>
    <form className="admin-edit-user-dialog admin-gift-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-gift-title" onSubmit={submit}>
      <div className="admin-edit-user-head"><span className="admin-confirm-icon"><AdminIcon name="gift" size={22}/></span><span><h2 id="admin-gift-title">{editing ? 'Edit Gift' : 'Add Gift'}</h2><p>WebP images are stored locally in the website gifts folder.</p></span></div>
      <div className="admin-gift-editor-grid">
        <label className="admin-gift-upload"><input type="file" accept="image/webp,.webp" onChange={(event) => setImageFile(event.target.files?.[0] || null)} required={!editing}/>{previewUrl ? <img src={previewUrl} alt="Gift preview"/> : <span><AdminIcon name="gift" size={30}/>Choose WebP image</span>}</label>
        <div>
          <label className="admin-edit-field"><span>Gift name</span><input value={name} onChange={(event) => setName(event.target.value)} maxLength={80} required autoFocus/></label>
          <label className="admin-edit-field"><span>Price</span><input type="number" min="0.01" max="10000" step="0.01" value={price} onChange={(event) => setPrice(event.target.value)} required/></label>
          <label className="admin-edit-field"><span>Display order</span><input type="number" min="0" max="1000000" step="1" value={sortOrder} onChange={(event) => setSortOrder(event.target.value)} required/></label>
          <label className="admin-gift-enabled"><input type="checkbox" checked={enabled} onChange={(event) => setEnabled(event.target.checked)}/><span>Enabled for live streams</span></label>
        </div>
      </div>
      <div className="admin-edit-actions"><button type="button" className="admin-confirm-button cancel" onClick={onClose} disabled={saving}>Cancel</button><button type="submit" className="admin-confirm-button primary" disabled={saving || !name.trim() || Number(price) <= 0 || (!editing && !imageFile)}>{saving ? 'Saving...' : editing ? 'Save changes' : 'Add Gift'}</button></div>
    </form>
  </div>;
}

function SuperStickersPage({ api = null }) {
  const defaults = { provider: 'klipy', enabled: false, api_key: '', api_key_configured: false, locale: 'us', content_filter: 'high', live_price_cents: 100, per_page: 24 };
  const [settings, setSettings] = useState(defaults);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [clearApiKey, setClearApiKey] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const requestGeneration = useRef(0);
  const applySettings = (saved = {}) => {
    // Only the configured boolean is returned by the server. Never preload a key.
    setSettings({ provider: 'klipy', enabled: Boolean(saved.enabled), api_key: '', api_key_configured: Boolean(saved.api_key_configured), locale: saved.locale || 'us', content_filter: saved.content_filter || 'high', live_price_cents: saved.live_price_cents ?? 100, per_page: saved.per_page ?? 24 });
    setClearApiKey(false);
    setDirty(false);
  };
  const loadSettings = useCallback(async () => {
    const generation = ++requestGeneration.current;
    setLoading(true);
    setError('');
    try {
      if (!api?.request) throw new Error('The administrator API is unavailable.');
      const data = await api.request('/admin/stickers/settings');
      if (generation === requestGeneration.current) applySettings(data.settings);
    } catch (error) {
      if (generation === requestGeneration.current) setError(error.message || 'Unable to load KLIPY settings.');
    } finally {
      if (generation === requestGeneration.current) setLoading(false);
    }
  }, [api]);
  useEffect(() => { void loadSettings(); return () => { requestGeneration.current += 1; }; }, [loadSettings]);
  const change = (name, value) => { setSettings((current) => ({ ...current, [name]: value })); setDirty(true); setNotice(''); };
  const saveSettings = async (event) => {
    event.preventDefault();
    if (saving || testing || loading || !api?.request) return;
    const generation = requestGeneration.current;
    setSaving(true);
    setError(''); setNotice('');
    try {
      const data = await api.request('/admin/stickers/settings', { method: 'PUT', body: JSON.stringify({ enabled: settings.enabled, api_key: settings.api_key.trim(), clear_api_key: clearApiKey, locale: settings.locale.trim(), content_filter: settings.content_filter, live_price_cents: Number(settings.live_price_cents), per_page: Number(settings.per_page) }) });
      if (generation !== requestGeneration.current) return;
      applySettings(data.settings);
      setNotice('KLIPY settings saved for the website and mobile app.');
    } catch (error) {
      if (generation === requestGeneration.current) setError(error.message || 'Unable to save KLIPY settings.');
    } finally {
      if (generation === requestGeneration.current) setSaving(false);
    }
  };
  const testConnection = async () => {
    if (saving || testing || dirty || !api?.request) return;
    const generation = requestGeneration.current;
    setTesting(true); setError(''); setNotice('');
    try {
      const data = await api.request('/admin/stickers/test', { method: 'POST', body: JSON.stringify({}) });
      if (generation !== requestGeneration.current) return;
      await fetchKlipyStickerPage(data.client);
      if (generation !== requestGeneration.current) return;
      setNotice('KLIPY connection verified directly from this browser.');
    } catch (error) {
      if (generation === requestGeneration.current) setError(error.message || 'Unable to connect to KLIPY.');
    } finally {
      if (generation === requestGeneration.current) setTesting(false);
    }
  };
  return <div className="admin-main-stack"><TablePanel title="KLIPY sticker integration">
    <form className="admin-klipy-settings" onSubmit={saveSettings}>
      <p>Stickers are supplied by KLIPY. No local sticker folder, image uploads, or sticker catalog maintenance is required. This configuration is shared by the website and mobile app.</p>
      <p><a href="https://partner.klipy.com" target="_blank" rel="noopener noreferrer">Get your KLIPY app key</a>. Use a non-monetized key: ad-enabled keys are not supported. Requests are made directly by the website and app using this client-facing key. Do not enter a private service credential.</p>
      {loading && <p role="status">Loading KLIPY settings...</p>}
      {error && <div className="admin-klipy-notice error" role="alert">{error}{!loading && !settings.api_key_configured && !dirty && <button type="button" onClick={loadSettings}>Reload settings</button>}</div>}
      {notice && <p className="admin-klipy-notice" role="status">{notice}</p>}
      <fieldset disabled={loading || saving || testing || !api?.request}>
        <label className="admin-gift-enabled"><input type="checkbox" checked={settings.enabled} onChange={(event) => change('enabled', event.target.checked)}/><span>Enable KLIPY stickers</span></label>
        <div className="admin-klipy-fields">
          <label className="admin-edit-field"><span>KLIPY app key</span><input type="password" value={settings.api_key} onChange={(event) => { change('api_key', event.target.value); setClearApiKey(false); }} placeholder={settings.api_key_configured ? 'Configured — leave blank to keep the saved key' : 'Paste your KLIPY app key'} autoComplete="new-password" maxLength={256}/><small>{settings.api_key_configured ? 'A key is configured. This field is blank to avoid accidental replacement.' : 'No KLIPY app key is configured.'}</small></label>
          <label className="admin-edit-field"><span>Locale</span><input value={settings.locale} onChange={(event) => change('locale', event.target.value)} placeholder="us" maxLength={20} required/><small>Use a KLIPY locale such as us.</small></label>
          <label className="admin-edit-field"><span>Content filter</span><AdaptiveSelect value={settings.content_filter} onChange={(event) => change('content_filter', event.target.value)}><option value="high">High (recommended)</option><option value="medium">Medium</option><option value="low">Low</option><option value="off">Off</option></AdaptiveSelect></label>
          <label className="admin-edit-field"><span>Live Super Sticker price (cents)</span><input type="number" value={settings.live_price_cents} onChange={(event) => change('live_price_cents', event.target.value)} min="1" max="10000000" step="1" required/><small>Comment stickers remain free. Live contributions use the platform payment currency.</small></label>
          <label className="admin-edit-field"><span>Default results per page</span><input type="number" value={settings.per_page} onChange={(event) => change('per_page', event.target.value)} min="8" max="50" step="1" required/></label>
        </div>
        {settings.api_key_configured && <label className="admin-gift-enabled"><input type="checkbox" checked={clearApiKey} onChange={(event) => { setClearApiKey(event.target.checked); setDirty(true); setNotice(''); if (event.target.checked) setSettings((current) => ({ ...current, api_key: '' })); }}/><span>Remove the saved API key when saving (stickers will become unavailable)</span></label>}
        <div className="admin-klipy-actions"><button type="submit" className="admin-outline-action primary-text" disabled={saving || testing}>{saving ? 'Saving...' : 'Save KLIPY settings'}</button><button type="button" className="admin-outline-action" onClick={testConnection} disabled={dirty || !settings.api_key_configured || saving || testing}>{testing ? 'Testing...' : 'Test saved connection'}</button>{dirty && <small>Save your changes before testing.</small>}</div>
      </fieldset>
      <a className="klipy-attribution" href="https://klipy.com" target="_blank" rel="noopener noreferrer" data-i18n-skip>Powered by KLIPY</a>
    </form>
  </TablePanel></div>;
}

function WebsiteAdsPage({ api = null, confirmAction = null, alertAction = null }) {
  const [ads, setAds] = useState([]);
  const [summary, setSummary] = useState({ total: 0, active: 0, impressions: 0, clicks: 0 });
  const [loading, setLoading] = useState(true);
  const [editorAd, setEditorAd] = useState(null);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  const loadAds = useCallback(async () => {
    if (!api?.request) return;
    setLoading(true);
    try {
      const data = await api.request('/admin/website-ads');
      setAds(data.ads || []);
      setSummary(data.summary || {});
    } catch (error) {
      await alertAction?.({ title: 'Unable to load Ads', message: error.message || 'The advertising catalog could not be loaded.', tone: 'danger' });
    } finally {
      setLoading(false);
    }
  }, [api, alertAction]);

  useEffect(() => { loadAds(); }, [loadAds]);

  const saveAd = async (payload, ad = editorAd) => {
    if (saving) return;
    setSaving(true);
    try {
      await api.request(ad?.id ? `/admin/website-ads/${ad.id}` : '/admin/website-ads', { method: ad?.id ? 'PUT' : 'POST', body: JSON.stringify(payload) });
      setEditorAd(null);
      await loadAds();
    } catch (error) {
      await alertAction?.({ title: 'Unable to save Ad', message: error.message || 'The ad could not be saved.', tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  const toggleAd = (ad) => saveAd({ ...ad, enabled: !ad.enabled, starts_at: ad.starts_at || null, ends_at: ad.ends_at || null }, ad);
  const deleteAd = async (ad) => {
    const confirmed = await confirmAction?.({ title: 'Delete Ad?', message: `Delete "${ad.name}" from every website placement?`, confirmLabel: 'Delete Ad', busyLabel: 'Deleting...', icon: 'trash', danger: true });
    if (!confirmed) return;
    setDeletingId(ad.id);
    try {
      await api.request(`/admin/website-ads/${ad.id}`, { method: 'DELETE' });
      await loadAds();
    } catch (error) {
      await alertAction?.({ title: 'Unable to delete Ad', message: error.message || 'The ad could not be deleted.', tone: 'danger' });
    } finally {
      setDeletingId(null);
    }
  };

  const placementLabel = (value) => websiteAdPlacements.find(([key]) => key === value)?.[1] || value;
  const metrics = [
    { label: 'Total Ads', value: String(summary.total || 0), change: `${summary.active || 0} active`, icon: 'ad', tone: 'violet', points: [] },
    { label: 'Active Placements', value: String(summary.active || 0), change: `${new Set(ads.map((ad) => ad.placement)).size} placement types`, icon: 'target', tone: 'blue', points: [] },
    { label: 'Impressions', value: Number(summary.impressions || 0).toLocaleString(), change: 'Recorded views', icon: 'eye', tone: 'green', points: [] },
    { label: 'Clicks', value: Number(summary.clicks || 0).toLocaleString(), change: `${summary.impressions ? ((summary.clicks / summary.impressions) * 100).toFixed(2) : '0.00'}% CTR`, icon: 'activity', tone: 'orange', points: [] },
  ];
  const rows = ads.map((ad) => ({ id: `website-ad-${ad.id}`, cells: [
    <span className="admin-ad-name-cell"><span><AdminIcon name="ad" size={18}/></span><span><strong>{ad.name}</strong><small>{ad.creative_type.toUpperCase()} · {ad.display_style}</small></span></span>,
    placementLabel(ad.placement),
    <span className="admin-ad-targeting"><strong>{ad.device}</strong><small>{ad.audience}</small></span>,
    <StatusPill label={ad.enabled ? 'Enabled' : 'Disabled'} />,
    Number(ad.impressions || 0).toLocaleString(),
    Number(ad.clicks || 0).toLocaleString(),
    `${Number(ad.ctr || 0).toFixed(2)}%`,
    <AdminRowActions label={ad.name} actions={[
      { label: 'Edit Ad', icon: 'edit', onClick: () => setEditorAd(ad) },
      { label: ad.enabled ? 'Disable Ad' : 'Enable Ad', icon: ad.enabled ? 'pauseCircle' : 'play', onClick: () => toggleAd(ad) },
      { label: deletingId === ad.id ? 'Deleting...' : 'Delete Ad', icon: 'trash', danger: true, onClick: () => deleteAd(ad) },
    ]}/>,
  ] }));

  return <>
    <div className="admin-main-stack admin-ads-page">
      <MetricGrid metrics={metrics} columns={4}/>
      <TablePanel title="Website Ads" actionButton="Create Ad" actionIcon="ad" onAction={() => setEditorAd({})} flush>
        {loading ? <div className="admin-table-loading">Loading Ads...</div> : <SimpleTable columns={['Ad', 'Placement', 'Targeting', 'Status', 'Impressions', 'Clicks', 'CTR', 'Actions']} rows={rows}/>} 
      </TablePanel>
    </div>
    {editorAd && <WebsiteAdEditorDialog ad={editorAd} saving={saving} onClose={() => !saving && setEditorAd(null)} onSave={saveAd}/>} 
  </>;
}

function adminAdDateTime(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

function WebsiteAdEditorDialog({ ad, saving, onClose, onSave }) {
  const editing = Boolean(ad?.id);
  const startsAtRef = useRef(null);
  const endsAtRef = useRef(null);
  const [form, setForm] = useState({
    name: ad?.name || '', placement: ad?.placement || 'header_after', creative_type: ad?.creative_type || 'image',
    title: ad?.title || '', body: ad?.body || '', html_content: ad?.html_content || '', image_url: ad?.image_url || '', target_url: ad?.target_url || '',
    display_style: ad?.display_style || 'card', device: ad?.device || 'all', audience: ad?.audience || 'all', label: ad?.label || 'Sponsored',
    background_color: ad?.background_color || '', height: ad?.height || 90, max_width: ad?.max_width || '', every_n_items: ad?.every_n_items || 6,
    priority: ad?.priority || 0, enabled: ad?.enabled ?? true, dismissible: ad?.dismissible ?? false, open_new_tab: ad?.open_new_tab ?? true,
    show_label: ad?.show_label ?? true, starts_at: adminAdDateTime(ad?.starts_at), ends_at: adminAdDateTime(ad?.ends_at),
  });
  const setValue = (key, value) => setForm((current) => ({ ...current, [key]: value }));
  const submit = (event) => {
    event.preventDefault();
    onSave({ ...form, height: Number(form.height), max_width: form.max_width ? Number(form.max_width) : null, every_n_items: Number(form.every_n_items), priority: Number(form.priority), starts_at: form.starts_at || null, ends_at: form.ends_at || null });
  };
  const creativeReady = form.creative_type === 'html' ? form.html_content.trim() : form.creative_type === 'image' ? form.image_url.trim() : (form.title.trim() || form.body.trim());
  const openCalendar = (inputRef) => {
    const input = inputRef.current;
    if (!input) return;
    input.focus();
    if (typeof input.showPicker === 'function') {
      try { input.showPicker(); } catch (_) { input.click(); }
    } else input.click();
  };

  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape' && !saving) onClose(); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [onClose, saving]);

  return <div className="admin-confirm-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && !saving && onClose()}>
    <form className="admin-edit-user-dialog admin-ad-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-ad-title" onSubmit={submit}>
      <div className="admin-edit-user-head admin-ad-dialog-head"><span className="admin-confirm-icon"><AdminIcon name="ad" size={22}/></span><span><h2 id="admin-ad-title">{editing ? 'Edit Ad' : 'Create Ad'}</h2><p>Configure the creative, placement, targeting, schedule, and display behavior.</p></span><button type="button" className="admin-edit-dialog-close" onClick={onClose} disabled={saving} aria-label="Close ad editor"><AdminIcon name="close" size={18}/></button></div>
      <div className="admin-ad-editor-body">
        <section className="admin-ad-fields">
          <div className="admin-ad-field-grid two"><label className="admin-edit-field"><span>Internal name</span><input value={form.name} onChange={(event) => setValue('name', event.target.value)} maxLength={120} required autoFocus/></label><label className="admin-edit-field"><span>Placement</span><AdaptiveSelect value={form.placement} onChange={(event) => setValue('placement', event.target.value)}>{websiteAdPlacements.map(([value, label]) => <option value={value} key={value}>{label}</option>)}</AdaptiveSelect></label></div>
          <div className="admin-ad-field-grid three"><label className="admin-edit-field"><span>Creative type</span><AdaptiveSelect value={form.creative_type} onChange={(event) => setValue('creative_type', event.target.value)}><option value="image">Image</option><option value="html">HTML</option><option value="text">Text</option></AdaptiveSelect></label><label className="admin-edit-field"><span>Display style</span><AdaptiveSelect value={form.display_style} onChange={(event) => setValue('display_style', event.target.value)}><option value="card">Card</option><option value="banner">Banner</option><option value="seamless">Seamless</option><option value="sticky">Sticky</option><option value="floating">Floating</option></AdaptiveSelect></label><label className="admin-edit-field"><span>Priority</span><input type="number" min="-100000" max="100000" value={form.priority} onChange={(event) => setValue('priority', event.target.value)} required/></label></div>
          {form.creative_type === 'html' && <label className="admin-edit-field"><span>HTML content</span><textarea className="admin-ad-code" rows={9} value={form.html_content} onChange={(event) => setValue('html_content', event.target.value)} maxLength={100000} placeholder="<div>Your ad markup</div>" required/></label>}
          {form.creative_type === 'image' && <label className="admin-edit-field"><span>Image URL</span><input value={form.image_url} onChange={(event) => setValue('image_url', event.target.value)} maxLength={2048} placeholder="https://... or /uploads/..." required/></label>}
          {form.creative_type !== 'html' && <><label className="admin-edit-field"><span>Headline</span><input value={form.title} onChange={(event) => setValue('title', event.target.value)} maxLength={180}/></label><label className="admin-edit-field"><span>Description</span><textarea rows={3} value={form.body} onChange={(event) => setValue('body', event.target.value)} maxLength={5000}/></label></>}
          <label className="admin-edit-field"><span>Destination URL</span><input value={form.target_url} onChange={(event) => setValue('target_url', event.target.value)} maxLength={2048} placeholder="https://... or /plans"/></label>
          <div className="admin-ad-field-grid three"><label className="admin-edit-field"><span>Device</span><AdaptiveSelect value={form.device} onChange={(event) => setValue('device', event.target.value)}><option value="all">All devices</option><option value="desktop">Desktop</option><option value="mobile">Mobile</option><option value="tablet">Tablet</option></AdaptiveSelect></label><label className="admin-edit-field"><span>Audience</span><AdaptiveSelect value={form.audience} onChange={(event) => setValue('audience', event.target.value)}><option value="all">Everyone</option><option value="guests">Guests</option><option value="members">Logged-in users</option></AdaptiveSelect></label><label className="admin-edit-field"><span>Insert every</span><input type="number" min="1" max="50" value={form.every_n_items} onChange={(event) => setValue('every_n_items', event.target.value)} required/><small>Items in feed placements</small></label></div>
          <div className="admin-ad-field-grid three"><label className="admin-edit-field"><span>Height (px)</span><input type="number" min="50" max="1200" value={form.height} onChange={(event) => setValue('height', event.target.value)} required/></label><label className="admin-edit-field"><span>Maximum width (px)</span><input type="number" min="200" max="2400" value={form.max_width} onChange={(event) => setValue('max_width', event.target.value)} placeholder="Automatic"/></label><label className="admin-edit-field"><span>Background color</span><input type="text" pattern="#[0-9a-fA-F]{3,8}" value={form.background_color} onChange={(event) => setValue('background_color', event.target.value)} placeholder="#ffffff"/></label></div>
          <div className="admin-ad-field-grid two"><label className="admin-edit-field"><span>Starts at</span><div className="admin-ad-date-field"><input ref={startsAtRef} type="datetime-local" value={form.starts_at} onChange={(event) => setValue('starts_at', event.target.value)}/><button type="button" onClick={() => openCalendar(startsAtRef)} aria-label="Open start calendar"><AdminIcon name="calendar" size={17}/></button></div></label><label className="admin-edit-field"><span>Ends at</span><div className="admin-ad-date-field"><input ref={endsAtRef} type="datetime-local" min={form.starts_at || undefined} value={form.ends_at} onChange={(event) => setValue('ends_at', event.target.value)}/><button type="button" onClick={() => openCalendar(endsAtRef)} aria-label="Open end calendar"><AdminIcon name="calendar" size={17}/></button></div></label></div>
          <div className="admin-ad-field-grid two"><label className="admin-edit-field"><span>Ad label</span><input value={form.label} onChange={(event) => setValue('label', event.target.value)} maxLength={40}/></label><div className="admin-ad-switches">{[['enabled','Enabled'],['show_label','Show sponsored label'],['dismissible','Allow dismissal'],['open_new_tab','Open links in new tab']].map(([key,label]) => <label className="admin-gift-enabled" key={key}><input type="checkbox" checked={form[key]} onChange={(event) => setValue(key, event.target.checked)}/><span>{label}</span></label>)}</div></div>
        </section>
        <aside className="admin-ad-preview-panel"><span>Preview</span><AdminAdPreview ad={form}/><small>HTML ads run in an isolated sandbox on the public website.</small></aside>
      </div>
      <div className="admin-edit-actions"><button type="button" className="admin-confirm-button cancel" onClick={onClose} disabled={saving}>Cancel</button><button type="submit" className="admin-confirm-button primary" disabled={saving || !form.name.trim() || !creativeReady}>{saving ? 'Saving...' : editing ? 'Save changes' : 'Create Ad'}</button></div>
    </form>
  </div>;
}

function AdminAdPreview({ ad }) {
  const style = { minHeight: `${Math.min(320, Math.max(70, Number(ad.height) || 90))}px`, backgroundColor: ad.background_color || undefined };
  if (ad.creative_type === 'html') return <div className="admin-ad-preview" style={style}>{ad.html_content ? <iframe title="HTML ad preview" sandbox="allow-scripts allow-forms" srcDoc={ad.html_content}/> : <span>Enter HTML to preview the ad.</span>}</div>;
  if (ad.creative_type === 'image') return <div className="admin-ad-preview" style={style}>{ad.image_url ? <img src={ad.image_url} alt=""/> : <span>Enter an image URL to preview the ad.</span>}</div>;
  return <div className="admin-ad-preview text" style={style}><strong>{ad.title || 'Ad headline'}</strong><p>{ad.body || 'Ad description'}</p></div>;
}

function ContentPage({ navigate, data = {}, api = null, onRefresh = () => {}, confirmAction = null, alertAction = null, promptAction = null, routeSearch = '', canManageAdmin = true }) {
  const fallbackRows = data.rows || [];
  const tabs = data.tabs || [['All Content', '0'], ['Videos', '0'], ['Shorts', '0'], ['Live Replays', '0']];
  const [activeTab, setActiveTab] = useState(tabs[0]?.[0] || 'All Content');
  const routeSearchTerm = adminRouteParam(routeSearch, 'search');
  const routeStatusTerm = adminRouteParam(routeSearch, 'status');
  const [tableData, setTableData] = useState(null);
  const [tablePage, setTablePage] = useState(1);
  const [tableLoading, setTableLoading] = useState(false);
  const [tableReload, setTableReload] = useState(0);
  const [tableSearch, setTableSearch] = useState(routeSearchTerm);
  const [typeFilter, setTypeFilter] = useState('All');
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [visibilityFilter, setVisibilityFilter] = useState('All');
  const [statusFilter, setStatusFilter] = useState(routeStatusTerm || 'All');
  const [moreFiltersOpen, setMoreFiltersOpen] = useState(false);
  const [uploadedAfter, setUploadedAfter] = useState('');
  const [uploadedBefore, setUploadedBefore] = useState('');
  const [minViews, setMinViews] = useState('');
  useEffect(() => setTableSearch(routeSearchTerm), [routeSearchTerm]);
  useEffect(() => {
    if (routeStatusTerm) setStatusFilter(routeStatusTerm);
  }, [routeStatusTerm]);
  const rows = tableData?.rows || fallbackRows;
  const highlightId = useAdminRouteTarget(routeSearch, rows.length);
  const totalRows = tableData?.total ?? data.total ?? fallbackRows.length;
  const tableFrom = tableData?.from ?? data.from ?? (rows.length ? 1 : 0);
  const tableTo = tableData?.to ?? data.to ?? rows.length;
  const lastPage = tableData?.last_page || data.last_page || 1;
  const pageStart = Math.max(1, Math.min(tablePage - 2, Math.max(lastPage - 4, 1)));
  const paginationPages = Array.from({ length: Math.min(5, lastPage - pageStart + 1) }, (_, index) => String(pageStart + index));
  const typeOptions = ['All', 'Video', 'Short', 'Live', 'Live Replay'];
  const categoryOptions = Array.from(new Set(['All', 'Uncategorized', ...((data.massImport?.categories || []).map((category) => category.name).filter(Boolean))]));
  const visibilityOptions = ['All', 'Public', 'Unlisted', 'Private'];
  const statusOptions = ['All', 'Pending Review', 'Scheduled', 'Published', 'Processing', 'Live', 'Live Replay', 'Failed', 'Draft'];
  const refreshContentTable = () => {
    setTableReload((value) => value + 1);
    onRefresh?.();
  };

  useEffect(() => {
    const categories = data.massImport?.categories;
    if (Array.isArray(categories) && categoryFilter !== 'All' && categoryFilter !== 'Uncategorized'
      && !categories.some((category) => category.name === categoryFilter)) {
      setCategoryFilter('All');
    }
  }, [data.massImport?.categories, categoryFilter]);

  useEffect(() => {
    setTablePage(1);
  }, [activeTab, tableSearch, typeFilter, categoryFilter, visibilityFilter, statusFilter, uploadedAfter, uploadedBefore, minViews]);

  useEffect(() => {
    if (!api?.request) return undefined;

    let cancelled = false;
    const timeout = setTimeout(() => {
      const params = new URLSearchParams({ page: String(tablePage), per_page: '25' });
      if (tableSearch.trim()) params.set('search', tableSearch.trim());
      if (activeTab !== 'All Content') params.set('tab', activeTab);
      if (typeFilter !== 'All') params.set('type', typeFilter);
      if (categoryFilter !== 'All') params.set('category', categoryFilter);
      if (visibilityFilter !== 'All') params.set('visibility', visibilityFilter);
      if (statusFilter !== 'All') params.set('status', statusFilter);
      if (uploadedAfter) params.set('uploaded_after', uploadedAfter);
      if (uploadedBefore) params.set('uploaded_before', uploadedBefore);
      if (minViews !== '') params.set('min_views', minViews);

      setTableLoading(true);
      api.request(`/admin/content?${params.toString()}`)
        .then((payload) => {
          if (!cancelled) setTableData(payload || null);
        })
        .catch((error) => {
          if (!cancelled) alertAction?.({ title: 'Content search failed', message: error.message || 'Unable to search content.', tone: 'danger' });
        })
        .finally(() => {
          if (!cancelled) setTableLoading(false);
        });
    }, tableSearch.trim() ? 300 : 0);

    return () => {
      cancelled = true;
      clearTimeout(timeout);
    };
  }, [api, activeTab, tableSearch, typeFilter, categoryFilter, visibilityFilter, statusFilter, uploadedAfter, uploadedBefore, minViews, tablePage, tableReload]);

  const resetContentFilters = () => {
    setActiveTab('All Content');
    setTableSearch('');
    setTypeFilter('All');
    setCategoryFilter('All');
    setVisibilityFilter('All');
    setStatusFilter('All');
    setUploadedAfter('');
    setUploadedBefore('');
    setMinViews('');
    setMoreFiltersOpen(false);
  };
  const bulkContentFilters = {
    search: tableSearch.trim(),
    tab: activeTab,
    type: typeFilter,
    category: categoryFilter,
    visibility: visibilityFilter,
    status: statusFilter,
    uploaded_after: uploadedAfter,
    uploaded_before: uploadedBefore,
    min_views: minViews,
  };
  const runQuickAction = (action) => runAdminQuickAction(action, { navigate, api, data, alertAction, promptAction, onRefresh: refreshContentTable, contentFilters: bulkContentFilters });

  return (
    <>
      <MetricGrid metrics={data.metrics || []} />
      <div className="admin-content-grid content-grid">
        <div className="admin-main-stack">
          <TablePanel flush>
            <Tabs tabs={tabs} active={activeTab} onChange={setActiveTab} />
            <FilterBar
              search="Search by title, creator, or description..."
              value={tableSearch}
              onSearch={setTableSearch}
              selects={[
                { label: 'Type', value: typeFilter, options: typeOptions, onChange: setTypeFilter },
                { label: 'Category', value: categoryFilter, options: categoryOptions, onChange: setCategoryFilter },
                { label: 'Visibility', value: visibilityFilter, options: visibilityOptions, onChange: setVisibilityFilter },
                { label: 'Status', value: statusFilter, options: statusOptions, onChange: setStatusFilter },
              ]}
              reset
              onMore={() => setMoreFiltersOpen((open) => !open)}
              onReset={resetContentFilters}
              moreActive={moreFiltersOpen}
            />
            {moreFiltersOpen && <div className="admin-filter-extra">
              <label><span>Uploaded after</span><input type="date" value={uploadedAfter} onClick={(event) => event.currentTarget.showPicker?.()} onFocus={(event) => event.currentTarget.showPicker?.()} onChange={(event) => setUploadedAfter(event.target.value)} /></label>
              <label><span>Uploaded before</span><input type="date" value={uploadedBefore} onClick={(event) => event.currentTarget.showPicker?.()} onFocus={(event) => event.currentTarget.showPicker?.()} onChange={(event) => setUploadedBefore(event.target.value)} /></label>
              <label><span>Minimum views</span><input type="number" min="0" value={minViews} onChange={(event) => setMinViews(event.target.value)} placeholder="0" /></label>
            </div>}
            {tableLoading && <div className="admin-table-loading">Searching content...</div>}
            <ContentTable rows={rows} totalRows={totalRows} bulkFilters={bulkContentFilters} navigate={navigate} api={api} onRefresh={refreshContentTable} confirmAction={confirmAction} alertAction={alertAction} highlightId={highlightId} canManageAdmin={canManageAdmin} />
            <TableFooter text={`Showing ${tableFrom} to ${tableTo} of ${totalRows} content items`} pages={paginationPages} activePage={tablePage} lastPage={lastPage} onPageChange={setTablePage} />
          </TablePanel>
        </div>
        <aside className="admin-side-stack">
          <SideCard title="Content Types" action="View all" onAction={() => navigate('/admin/content')}><DonutMini items={data.contentTypes || data.types || []} center={data.contentCenter || data.tabs?.[0]?.[1] || '0'} sub="Total" /></SideCard>
          <SideCard title="Recent Uploads" action="View all" onAction={() => navigate('/admin/content')}><UploadList rows={data.recentUploads || fallbackRows.slice(0, 5)} /></SideCard>
          <SideCard title="Moderation Summary" action="View all" onAction={() => navigate('/admin/reports')}><KeyValueIconList rows={data.moderationSummary || [[ 'Pending Review', '0', 'warning'], ['Flagged Content', '0', 'flag'], ['Copyright Claims', '0', 'copyright'], ['Community Reports', '0', 'shield']]} /></SideCard>
          <QuickActionList actions={canManageAdmin ? ['Review Flagged Content', 'Bulk Update Categories', 'Schedule Content', 'Manage Content Categories'] : ['Review Flagged Content']} onAction={runQuickAction} />
        </aside>
      </div>
    </>
  );
}

function MetricGrid({ metrics = [], columns = 5 }) {
  return <section className={`admin-metric-grid columns-${columns}`} aria-label="Metrics">{metrics.map((metric) => <MetricCard metric={metric} key={metric.label} />)}</section>;
}

function MetricCard({ metric }) {
  return (
    <article className={`admin-metric-card tone-${metric.tone || 'violet'} ${metric.danger ? 'is-danger' : ''}`}>
      <div className="admin-metric-label">
        <span className={`admin-metric-icon tone-${metric.tone}`}><AdminIcon name={metric.icon} size={17} /></span>
        <span>{metric.label}</span>
      </div>
      <div className="admin-metric-content">
        <div>
          <strong>{metric.value}{metric.detail && <em>{metric.detail}</em>}</strong>
          {metric.change && <small className={metric.danger ? 'is-danger' : ''}><AdminIcon name={metric.danger ? 'trendDown' : 'trendUp'} size={12} /> {metric.change} <span>{metric.sub}</span></small>}
          {!metric.change && metric.sub && <small><span>{metric.sub}</span></small>}
          {metric.progress && <div className="admin-metric-progress"><i style={{ width: `${metric.progress}%` }} /></div>}
        </div>
        {!!metric.points?.length && <Sparkline points={metric.points} danger={metric.danger} />}
      </div>
    </article>
  );
}

function Sparkline({ points = zeroTrend, danger = false }) {
  const polyline = getPolylinePoints(points, 92, 42, 3);
  return (
    <svg className={`admin-mini-trend ${danger ? 'danger' : ''}`} viewBox="0 0 92 42" preserveAspectRatio="none" aria-hidden="true">
      <polyline points={polyline} fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function ChartPanel({ title, subtitle, value, change, yLabels = [], labels = [], series = [], legends = null, action = null, onAction = null }) {
  return (
    <article className="admin-card admin-chart-panel">
      <div className="admin-card-title-row">
        <div>
          <h2>{title}</h2>
          {subtitle && <p>{subtitle}</p>}
        </div>
        {(value || action) && <div className="admin-chart-meta">
          {value && <strong>{value}</strong>}
          {change && <small><AdminIcon name="trendUp" size={12} /> {change}</small>}
          {action && <button type="button" className="admin-small-select" onClick={onAction} disabled={!onAction}>{action} <AdminIcon name="chevronDown" size={13} /></button>}
        </div>}
      </div>
      {legends && <div className="admin-chart-legend">{legends.map(([label, tone]) => <span key={label}><i className={`legend-${tone}`} />{label}</span>)}</div>}
      <div className="admin-chart-body">
        <div className="admin-y-axis">{yLabels.map((label) => <span key={label}>{label}</span>)}</div>
        <div className="admin-chart-area">
          <LineChart series={series} />
          <div className="admin-x-axis">{labels.map((label, index) => <span key={`${label}-${index}`}>{label}</span>)}</div>
        </div>
      </div>
    </article>
  );
}

function LineChart({ series }) {
  const width = 640;
  const height = 180;
  const padding = 8;
  const safeSeries = series?.length ? series.map((item) => ({ ...item, points: item.points?.length ? item.points : zeroTrend })) : [{ points: zeroTrend, color: 'primary', area: true }];
  const values = safeSeries.flatMap((item) => item.points.map((point) => Number(point)).filter(Number.isFinite));
  const max = Math.max(...values, 1);
  const min = Math.min(0, ...values);
  return (
    <svg className="admin-line-chart" viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" aria-hidden="true">
      {[0, 1, 2, 3].map((line) => <line className="admin-grid-line" key={line} x1="0" x2={width} y1={padding + line * ((height - padding * 2) / 3)} y2={padding + line * ((height - padding * 2) / 3)} />)}
      {safeSeries.map((item, index) => {
        const coords = getChartCoordinates(item.points, width, height, padding, min, max);
        const linePath = coordsToPath(coords);
        const areaPath = `${linePath} L ${width - padding} ${height - padding} L ${padding} ${height - padding} Z`;
        return (
          <g key={`${item.color}-${index}`} className={`admin-series color-${item.color}`}>
            {item.area && <path className="admin-area" d={areaPath} />}
            <path className="admin-line" d={linePath} />
          </g>
        );
      })}
    </svg>
  );
}

function DonutPanel({ title, items = [], center, sub }) {
  const safeItems = items.length ? items : [{ label: 'No data', value: '0', color: '#c8cfda' }];
  return (
    <article className="admin-card admin-donut-panel">
      <div className="admin-card-title-row"><h2>{title}</h2></div>
      <div className="admin-donut-content">
        <DonutGraphic items={safeItems} center={center || '0'} sub={sub} />
        <div className="admin-donut-legend">
          {safeItems.map((item) => <div key={item.label}><i style={{ backgroundColor: adminVisualColor(item.color) }} /><span>{item.label}</span><strong>{item.value}</strong>{item.note && <em>{item.note}</em>}</div>)}
        </div>
      </div>
    </article>
  );
}

function DonutGraphic({ items, center, sub, small = false }) {
  const gradient = buildConicGradient(items.map((item) => [adminVisualColor(item.color), parseFloat(String(item.value).replace(/[^0-9.]/g, '')) || 10]));
  return <div className={`admin-donut ${small ? 'small' : ''}`} style={{ background: gradient }}><span><strong>{center}</strong><em>{sub}</em></span></div>;
}

function DonutMini({ items = [], center, sub }) {
  const safeItems = items.length ? items : [['No data', '0', '#c8cfda']];
  const normalized = safeItems.map(([label, value, color]) => ({ label, value, color }));
  return (
    <div className="admin-donut-mini-row">
      <DonutGraphic items={normalized} center={center || '0'} sub={sub} small />
      <LegendList rows={safeItems} compact />
    </div>
  );
}

function TablePanel({ title, action, actionButton, actionIcon = 'userPlus', children, onAction, flush = false }) {
  return (
    <article className={`admin-card admin-table-panel ${flush ? 'flush' : ''}`}>
      {(title || action || actionButton) && <div className="admin-card-title-row table-title">
        {title && <h2>{title}</h2>}
        {action && <button type="button" className="admin-text-button" onClick={onAction} disabled={!onAction}>{action}</button>}
        {actionButton && <button type="button" className="admin-action-button primary compact" onClick={onAction} disabled={!onAction}><AdminIcon name={actionIcon} size={16} />{actionButton}</button>}
      </div>}
      {children}
    </article>
  );
}

function Tabs({ tabs, subtle = false, active = null, onChange = null }) {
  const activeLabel = active || tabs[0]?.[0];
  return <div className={`admin-tabs ${subtle ? 'subtle' : ''}`}>{tabs.map(([label, count], index) => <button type="button" className={(active ? label === activeLabel : index === 0) ? 'active' : ''} key={label} onClick={() => onChange?.(label)} disabled={!onChange}>{label}{count && <span>{count}</span>}</button>)}</div>;
}

function FilterBar({ search, filters = [], reset = false, refresh = false, value = '', onSearch = null, selects = [], onMore = null, onRefresh = null, onReset = null, moreActive = false }) {
  const [localValue, setLocalValue] = useState('');
  const searchValue = onSearch ? value : localValue;
  const updateSearch = (next) => onSearch ? onSearch(next) : setLocalValue(next);
  return (
    <div className="admin-filter-bar">
      <label className="admin-filter-search"><AdminIcon name="search" size={17} /><input placeholder={search} value={searchValue} onChange={(event) => updateSearch(event.target.value)} />{searchValue && <button type="button" className="search-clear-button admin-search-clear" onClick={() => updateSearch('')} aria-label="Clear search"><AdminIcon name="close" size={14} /></button>}</label>
      {selects.length ? selects.map((select) => <label className="admin-filter-select" key={select.label}><span>{select.label}:</span><AdaptiveSelect value={select.value} onChange={(event) => select.onChange(event.target.value)}>{select.options.map((option) => <option value={option} key={option}>{option}</option>)}</AdaptiveSelect><AdminIcon name="chevronDown" size={14} /></label>) : filters.map((filter) => {
        const opensMore = filter.includes('More') || filter === 'Filters';
        if (!opensMore) return <span className="admin-filter-button static" key={filter}><span>{filter}</span><AdminIcon name="chevronDown" size={14} /></span>;
        if (!onMore) return null;
        return <button type="button" className="admin-filter-button" key={filter} onClick={onMore}><AdminIcon name="filter" size={15} /><span>{filter}</span></button>;
      })}
      {!!selects.length && onMore && <button type="button" className={`admin-filter-button ${moreActive ? 'active' : ''}`} onClick={onMore}><AdminIcon name="filter" size={15} /><span>More Filters</span></button>}
      {refresh && <button type="button" className="admin-square-button" onClick={onRefresh} disabled={!onRefresh}><AdminIcon name="refresh" size={16} /></button>}
      {reset && <button type="button" className="admin-filter-button reset" onClick={() => { updateSearch(''); onReset?.(); }}><AdminIcon name="refresh" size={15} /><span>Reset</span></button>}
    </div>
  );
}

function SimpleTable({ columns, rows, className = '' }) {
  const normalizedRows = rows.map((row) => Array.isArray(row) ? { cells: row } : row);
  const responsiveList = columns.some((column) => typeof column === 'string' && column === 'Actions');
  const primaryColumn = columns.findIndex((column) => typeof column === 'string');
  return (
    <div className={`admin-table-wrap ${className}`.trim()} data-responsive-list={responsiveList ? true : undefined} data-responsive-scroll={!responsiveList ? true : undefined}>
      <table className="admin-data-table">
        <thead><tr>{columns.map((column, index) => <th key={typeof column === 'string' ? column : `column-${index}`}>{column}</th>)}</tr></thead>
        <tbody>{normalizedRows.length ? normalizedRows.map((row, index) => <tr key={row.id || index} id={row.id || undefined} className={row.highlight ? 'admin-row-highlight' : undefined}>{row.cells.map((cell, cellIndex) => <td key={cellIndex} data-label={typeof columns[cellIndex] === 'string' ? columns[cellIndex] : undefined} data-primary-cell={cellIndex === primaryColumn ? true : undefined} data-row-actions={columns[cellIndex] === 'Actions' ? true : undefined} data-row-selection={typeof columns[cellIndex] !== 'string' ? true : undefined}>{cell}</td>)}</tr>) : <tr><td colSpan={columns.length}>No records found.</td></tr>}</tbody>
      </table>
    </div>
  );
}

function UsersTable({ rows, navigate, api, onRefresh, confirmAction, editUserAction, passwordUserAction, roleUserAction, emailUserAction, banUserAction, alertAction, promptAction, highlightId = '', canManageAdmin = true }) {
  const [selectedIds, setSelectedIds] = useState([]);
  const visibleIds = canManageAdmin ? rows.map((row) => row.id).filter(Boolean) : [];
  const selectedVisibleIds = visibleIds.filter((id) => selectedIds.includes(id));
  const selectedRows = rows.filter((row) => selectedIds.includes(row.id));
  const selectedEmails = selectedRows.map((row) => row.person?.email).filter(Boolean);
  const allVisibleSelected = visibleIds.length > 0 && selectedVisibleIds.length === visibleIds.length;
  const partiallySelected = selectedVisibleIds.length > 0 && !allVisibleSelected;
  const toggleAllVisible = () => {
    setSelectedIds((ids) => {
      if (allVisibleSelected) return ids.filter((id) => !visibleIds.includes(id));
      return Array.from(new Set([...ids, ...visibleIds]));
    });
  };
  const toggleRow = (id) => {
    setSelectedIds((ids) => ids.includes(id) ? ids.filter((selectedId) => selectedId !== id) : [...ids, id]);
  };
  const runSelectedAction = async (action) => {
    if (!selectedRows.length) {
      await alertAction?.({ title: 'No users selected', message: 'Select at least one user before running a bulk action.', tone: 'danger' });
      return;
    }

    if (action === 'verify') {
      if (!selectedEmails.length) return;
      await adminJsonAction(api, '/admin/users/bulk-verify', { method: 'POST', body: JSON.stringify({ emails: selectedEmails }) }, () => {
        setSelectedIds([]);
        onRefresh?.();
      }, alertAction);
      return;
    }

    const deleting = action === 'delete';
    const confirmed = confirmAction ? await confirmAction({
      title: deleting ? 'Delete selected users' : 'Suspend selected users',
      message: deleting
        ? `Permanently delete ${selectedIds.length} selected user(s) and all related account data? This cannot be undone.`
        : `Suspend ${selectedEmails.length} selected user(s)? They will be signed out and blocked from logging in.`,
      confirmLabel: deleting ? 'Delete users' : 'Suspend users',
      tone: 'danger',
      ...(deleting ? { checkbox: { name: 'deleteVideos', label: 'Also permanently delete all videos uploaded by these users', required: true } } : {}),
    }) : true;
    if (!confirmed) return;

    await adminJsonAction(api, deleting ? '/admin/users/bulk-delete' : '/admin/users/bulk-status', { method: 'POST', body: JSON.stringify(deleting ? { ids: selectedIds, delete_videos: Boolean(confirmed.deleteVideos) } : { emails: selectedEmails, status: 'suspended' }) }, () => {
      setSelectedIds([]);
      onRefresh?.();
    }, alertAction);
  };

  return <>
    {canManageAdmin && !!selectedRows.length && <div className="admin-selection-bar">
      <span>{selectedRows.length} user{selectedRows.length === 1 ? '' : 's'} selected</span>
      <div className="admin-selection-actions">
        <button type="button" onClick={() => runSelectedAction('verify')}><AdminIcon name="shield" size={15} />Verify selected</button>
        <button type="button" className="danger" onClick={() => runSelectedAction('suspend')}><AdminIcon name="pauseCircle" size={15} />Suspend selected</button>
        <button type="button" className="danger" onClick={() => runSelectedAction('delete')}><AdminIcon name="trash" size={15} />Delete selected</button>
        <button type="button" className="muted" onClick={() => setSelectedIds([])}>Clear selection</button>
      </div>
    </div>}
    <SimpleTable columns={[canManageAdmin && <CheckBox checked={allVisibleSelected} indeterminate={partiallySelected} onChange={toggleAllVisible} label="Select all visible users" />, 'User', 'Role', 'Status', 'Membership', 'Signup', 'Signup Date', 'Last Active', 'Actions'].filter(Boolean)} rows={rows.map((row) => {
      const rowId = row.id ? `user-${row.id}` : '';
      return { id: rowId, highlight: highlightId === rowId, cells: [canManageAdmin && <CheckBox checked={selectedIds.includes(row.id)} onChange={() => row.id && toggleRow(row.id)} label={`Select ${row.person?.name || 'user'}`} />, <UserProfileLink row={row} />, <Tag label={row.role} tone={row.role === 'Creator' ? 'purple' : row.role === 'Moderator' ? 'orange' : 'blue'} />, <StatusPill label={row.status} />, <Tag label={row.membership || 'Starter'} tone={row.raw?.membership === 'vip' ? 'purple' : row.raw?.membership === 'pro' ? 'blue' : row.raw?.membership === 'premium' ? 'pink' : row.raw?.membership === 'plus' ? 'green' : 'slate'} />, <span className="admin-user-signup"><strong>{row.registration_provider_label || 'Local registration'}</strong>{canManageAdmin && <small data-i18n-skip>{row.registration_ip || 'IP not recorded'}</small>}</span>, row.date, row.active, <UserActionsMenu row={row} navigate={navigate} api={api} onRefresh={onRefresh} confirmAction={confirmAction} editUserAction={editUserAction} passwordUserAction={passwordUserAction} roleUserAction={roleUserAction} emailUserAction={emailUserAction} banUserAction={banUserAction} alertAction={alertAction} promptAction={promptAction} canManageAdmin={canManageAdmin} />].filter(Boolean) };
    })} />
  </>;
}

function UserProfileLink({ row }) {
  return <a className="admin-user-profile-link" href={row.profileUrl || '/profile'} target="_blank" rel="noopener noreferrer"><PersonCell person={row.person} showEmail /></a>;
}

function UserActionsMenu({ row, navigate, api, onRefresh, confirmAction, editUserAction, passwordUserAction, roleUserAction, emailUserAction, banUserAction, alertAction, promptAction, canManageAdmin = true }) {
  const [open, setOpen] = useState(false);
  const [menuStyle, setMenuStyle] = useState(null);
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const person = row?.person || {};

  const toggleMenu = () => {
    if (open) {
      setOpen(false);
      return;
    }
    const rect = buttonRef.current?.getBoundingClientRect();
    if (rect && typeof window !== 'undefined') {
      const width = 264;
      const estimatedHeight = row?.raw?.active_ban_scopes?.length ? 616 : 568;
      const height = Math.min(estimatedHeight, Math.max(260, window.innerHeight - 24));
      const spaceBelow = window.innerHeight - rect.bottom - 12;
      const spaceAbove = rect.top - 12;
      const opensUp = spaceBelow < height && spaceAbove > spaceBelow;
      setMenuStyle({
        left: `${Math.max(12, Math.min(rect.right - width, window.innerWidth - width - 12))}px`,
        top: `${opensUp ? Math.max(12, rect.top - height - 10) : Math.min(rect.bottom + 10, window.innerHeight - height - 12)}px`,
        maxHeight: `${height}px`,
        overflowY: estimatedHeight > height ? 'auto' : undefined,
      });
    }
    setOpen(true);
  };

  useEffect(() => {
    if (!open) return undefined;
    const close = () => setOpen(false);
    const closeOnOutside = (event) => {
      if (buttonRef.current?.contains(event.target) || menuRef.current?.contains(event.target)) return;
      close();
    };
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') close();
    };
    document.addEventListener('mousedown', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);
    document.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
    return () => {
      document.removeEventListener('mousedown', closeOnOutside);
      document.removeEventListener('keydown', closeOnEscape);
      document.removeEventListener('scroll', close, true);
      window.removeEventListener('resize', close);
    };
  }, [open]);

  const runAction = async (action) => {
    setOpen(false);
    await runUserRowAction(action, row, api, navigate, onRefresh, confirmAction, editUserAction, passwordUserAction, roleUserAction, emailUserAction, banUserAction, alertAction, promptAction);
  };
  const suspendLabel = String(row?.status || '').toLowerCase() === 'suspended' ? 'Restore user' : 'Suspend user';
  const hasActiveBans = Boolean(row?.raw?.active_ban_scopes?.length);

  return (
    <span className="admin-user-actions">
      <button type="button" ref={buttonRef} className={`admin-more-button ${open ? 'active' : ''}`} aria-label={`Open actions for ${person.name || 'user'}`} aria-haspopup="menu" aria-expanded={open} onClick={toggleMenu}>
        <AdminIcon name="more" size={16} />
      </button>
      {open && <div ref={menuRef} className="admin-row-action-menu" role="menu" style={menuStyle || undefined}>
        <button type="button" role="menuitem" onClick={() => runAction('profile')}><AdminIcon name="users" size={20} /><span>View profile</span></button>
        <button type="button" role="menuitem" onClick={() => runAction('activity')}><AdminIcon name="activity" size={20} /><span>View activity</span></button>
        {canManageAdmin && <button type="button" role="menuitem" onClick={() => runAction('edit')}><AdminIcon name="edit" size={20} /><span>Edit user</span></button>}
        {canManageAdmin && <button type="button" role="menuitem" onClick={() => runAction('password')}><AdminIcon name="key" size={20} /><span>Change password</span></button>}
        {canManageAdmin && <button type="button" role="menuitem" onClick={() => runAction('wallet-credit')}><AdminIcon name="monetization" size={20} /><span>Add wallet funds</span></button>}
        {canManageAdmin && <button type="button" role="menuitem" onClick={() => runAction('role')}><AdminIcon name="shield" size={20} /><span>Change role</span></button>}
        {canManageAdmin && <button type="button" role="menuitem" onClick={() => runAction('email')}><AdminIcon name="mail" size={20} /><span>Send email</span></button>}
        {canManageAdmin && !row.profile_verified && <button type="button" role="menuitem" onClick={() => runAction('profile-verify')}><AdminIcon name="verified" size={20} /><span>Verify profile badge</span></button>}
        {canManageAdmin && row.profile_verified && <button type="button" role="menuitem" onClick={() => runAction('profile-unverify')}><AdminIcon name="verified" size={20} /><span>Remove profile badge</span></button>}
        <button type="button" role="menuitem" onClick={() => runAction('ban')}><AdminIcon name="ban" size={20} /><span>Ban user</span></button>
        {hasActiveBans && <button type="button" role="menuitem" onClick={() => runAction('clear-bans')}><AdminIcon name="shield" size={20} /><span>Clear active bans</span></button>}
        <button type="button" role="menuitem" onClick={() => runAction('suspend')}><AdminIcon name="pauseCircle" size={20} /><span>{suspendLabel}</span></button>
        {canManageAdmin && <i aria-hidden="true" />}
        {canManageAdmin && <button type="button" role="menuitem" className="danger" onClick={() => runAction('delete')}><AdminIcon name="trash" size={20} /><span>Delete user</span></button>}
      </div>}
    </span>
  );
}

function CreatorsTable({ rows, navigate, api, onRefresh, confirmAction = null, alertAction, highlightId = '' }) {
  const [selectedIds, setSelectedIds] = useState([]);
  const visibleIds = rows.map((row) => row.id).filter(Boolean);
  const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedIds.includes(id));
  const someSelected = visibleIds.some((id) => selectedIds.includes(id)) && !allSelected;
  useEffect(() => setSelectedIds((ids) => ids.filter((id) => visibleIds.includes(id))), [visibleIds.join(',')]);
  const toggleAll = () => setSelectedIds((ids) => allSelected ? ids.filter((id) => !visibleIds.includes(id)) : Array.from(new Set([...ids, ...visibleIds])));
  const toggleOne = (id) => setSelectedIds((ids) => ids.includes(id) ? ids.filter((value) => value !== id) : [...ids, id]);
  const verifySelected = async () => {
    const identifiers = rows.filter((row) => selectedIds.includes(row.id)).map((row) => row.person?.handle || row.person?.email).filter(Boolean).join(', ');
    if (!identifiers) return;
    await adminJsonAction(api, '/admin/creators/bulk-verify', { method: 'POST', body: JSON.stringify({ identifiers }) }, () => { setSelectedIds([]); onRefresh?.(); }, alertAction);
  };
  return <>{!!selectedIds.length && <div className="admin-selection-bar"><span>{selectedIds.length} creator{selectedIds.length === 1 ? '' : 's'} selected</span><div className="admin-selection-actions"><button type="button" onClick={verifySelected}><AdminIcon name="verified" size={15}/>Verify selected</button><button type="button" className="muted" onClick={() => setSelectedIds([])}>Clear selection</button></div></div>}<SimpleTable columns={[<CheckBox checked={allSelected} indeterminate={someSelected} onChange={toggleAll} label="Select all creators" />, 'Creator', 'Handle', 'Category', 'Subscribers', '28-Day Views', 'Revenue (28d)', 'Verification', 'Actions']} rows={rows.map((row) => {
    const rowId = row.id ? `creator-${row.id}` : '';
    return { id: rowId, highlight: highlightId === rowId, cells: [<CheckBox checked={selectedIds.includes(row.id)} onChange={() => toggleOne(row.id)} label={`Select ${row.person?.name || 'creator'}`} />, <PersonCell person={row.person} />, row.person.handle, <Tag label={row.category} tone={categoryTone(row.category)} />, row.subscribers, row.views, row.revenue, <StatusPill label={row.verification} />, <AdminRowActions label={row.person?.name || 'creator'} actions={[
      { label: 'View channel', icon: 'external', onClick: () => runCreatorRowAction('view', row, api, navigate, onRefresh, confirmAction, alertAction) },
      { label: 'View content', icon: 'content', onClick: () => runCreatorRowAction('content', row, api, navigate, onRefresh, confirmAction, alertAction) },
      row.verification === 'Verified'
        ? { label: 'Remove profile badge', icon: 'verified', onClick: () => runCreatorRowAction('unverify', row, api, navigate, onRefresh, confirmAction, alertAction) }
        : { label: 'Verify profile badge', icon: 'verified', onClick: () => runCreatorRowAction('verify', row, api, navigate, onRefresh, confirmAction, alertAction) },
    ]} />] };
  })} /></>;
}

function ContentTable({ rows, totalRows = 0, bulkFilters = {}, navigate, api, onRefresh, confirmAction, alertAction, highlightId = '', canManageAdmin = true }) {
  const [selectedIds, setSelectedIds] = useState([]);
  const visibleIds = rows.map((row) => row.id).filter((id) => id != null);
  const selectedVisibleIds = visibleIds.filter((id) => selectedIds.includes(id));
  const selectedActionIds = selectedIds.filter((id) => id != null);
  const allVisibleSelected = visibleIds.length > 0 && selectedVisibleIds.length === visibleIds.length;
  const partiallySelected = selectedVisibleIds.length > 0 && !allVisibleSelected;
  const hasMoreMatchingRows = Number(totalRows || 0) > selectedActionIds.length;
  const canDeleteAllMatching = canManageAdmin && allVisibleSelected && hasMoreMatchingRows;
  const visibleKey = visibleIds.join(',');
  useEffect(() => {
    setSelectedIds([]);
  }, [visibleKey]);
  const toggleAllVisible = () => {
    setSelectedIds((ids) => {
      if (allVisibleSelected) return ids.filter((id) => !visibleIds.includes(id));
      return Array.from(new Set([...ids, ...visibleIds]));
    });
  };
  const toggleRow = (id) => {
    setSelectedIds((ids) => ids.includes(id) ? ids.filter((selectedId) => selectedId !== id) : [...ids, id]);
  };
  const runSelectedAction = async (action) => {
    if (!selectedActionIds.length) {
      await alertAction?.({ title: 'No videos selected', message: 'Select at least one video before running a bulk action.', tone: 'danger' });
      return;
    }

    if (['public', 'unlisted', 'private'].includes(action)) {
      await adminJsonAction(api, '/admin/content/videos/bulk-visibility', { method: 'POST', body: JSON.stringify({ ids: selectedActionIds, visibility: action }) }, () => {
        setSelectedIds([]);
        onRefresh?.();
      }, alertAction);
      return;
    }

    if (action === 'approve') {
      await adminJsonAction(api, '/admin/content/videos/bulk-approve', { method: 'POST', body: JSON.stringify({ ids: selectedActionIds }) }, () => {
        setSelectedIds([]);
        onRefresh?.();
      }, alertAction);
      return;
    }

    if (action === 'delete') {
      const confirmed = confirmAction ? await confirmAction({
        title: 'Delete selected videos',
        message: `Delete ${selectedActionIds.length} selected video(s)? This removes the videos and their media files.`,
        confirmLabel: 'Delete videos',
        tone: 'danger',
      }) : true;
      if (!confirmed) return;
      await adminJsonAction(api, '/admin/content/videos/bulk-delete', { method: 'POST', body: JSON.stringify({ ids: selectedActionIds }) }, () => {
        setSelectedIds([]);
        onRefresh?.();
      }, alertAction);
    }
  };
  const deleteAllMatching = async () => {
    const total = Number(totalRows || 0);
    if (!total) {
      await alertAction?.({ title: 'No videos found', message: 'There are no videos matching the current filters.', tone: 'danger' });
      return;
    }
    const confirmed = confirmAction ? await confirmAction({
      title: 'Delete all matching videos',
      message: `Delete all ${total} video(s) matching the current filters? This includes videos on every page, including imported videos, and removes their media files.`,
      confirmLabel: 'Delete all matching',
      tone: 'danger',
    }) : true;
    if (!confirmed) return;
    await adminJsonAction(api, '/admin/content/videos/bulk-delete', { method: 'POST', body: JSON.stringify({ ...bulkFilters, all: true }) }, () => {
      setSelectedIds([]);
      onRefresh?.();
    }, alertAction);
  };

  return <>
    {!!selectedActionIds.length && <div className="admin-selection-bar">
      <span>{selectedActionIds.length} video{selectedActionIds.length === 1 ? '' : 's'} selected on this page{hasMoreMatchingRows ? ` · ${totalRows} matching current filters` : ''}</span>
      <div className="admin-selection-actions">
        <button type="button" onClick={() => runSelectedAction('public')}><AdminIcon name="external" size={15} />Set public</button>
        <button type="button" onClick={() => runSelectedAction('unlisted')}><AdminIcon name="link" size={15} />Set unlisted</button>
        <button type="button" onClick={() => runSelectedAction('private')}><AdminIcon name="shield" size={15} />Set private</button>
        <button type="button" onClick={() => runSelectedAction('approve')}><AdminIcon name="verified" size={15} />Approve selected</button>
        <button type="button" className="danger" onClick={() => runSelectedAction('delete')}><AdminIcon name="trash" size={15} />Delete selected</button>
        {canDeleteAllMatching && <button type="button" className="danger" onClick={deleteAllMatching}><AdminIcon name="trash" size={15} />Delete all matching</button>}
        <button type="button" className="muted" onClick={() => setSelectedIds([])}>Clear selection</button>
      </div>
    </div>}
    <label className="admin-content-mobile-selection"><CheckBox checked={allVisibleSelected} indeterminate={partiallySelected} onChange={toggleAllVisible} label="Select all visible videos" /><span>Select all visible videos</span></label>
    <SimpleTable className="admin-content-table" columns={[<CheckBox checked={allVisibleSelected} indeterminate={partiallySelected} onChange={toggleAllVisible} label="Select all visible videos" />, 'Content', 'Creator', 'Type', 'Category', 'Visibility', 'Publish Date', 'Views', 'Status', 'Actions']} rows={rows.map((row) => {
      const rowId = row.id ? `video-${row.id}` : '';
      return { id: rowId, highlight: highlightId === rowId, cells: [<CheckBox checked={selectedIds.includes(row.id)} onChange={() => row.id && toggleRow(row.id)} label={`Select ${row.title || 'video'}`} />, <ContentCell row={row} />, <PersonCell person={row.creator} mini />, <Tag label={row.type} tone="purple" />, row.category, <StatusPill label={row.visibility} />, splitLines(row.date), row.views, <StatusPill label={row.status} />, <AdminRowActions label={row.title || 'content'} actions={[
        { label: 'Watch content', icon: 'play', onClick: () => runContentRowAction('view', row, api, navigate, onRefresh, confirmAction, alertAction) },
        { label: 'Edit content', icon: 'edit', onClick: () => runContentRowAction('edit', row, api, navigate, onRefresh, confirmAction, alertAction) },
        { divider: true },
        { label: 'Set public', icon: 'external', soft: String(row.visibility).toLowerCase() === 'public', onClick: () => runContentRowAction('public', row, api, navigate, onRefresh, confirmAction, alertAction) },
        { label: 'Set unlisted', icon: 'link', soft: String(row.visibility).toLowerCase() === 'unlisted', onClick: () => runContentRowAction('unlisted', row, api, navigate, onRefresh, confirmAction, alertAction) },
        { label: 'Set private', icon: 'shield', soft: String(row.visibility).toLowerCase() === 'private', onClick: () => runContentRowAction('private', row, api, navigate, onRefresh, confirmAction, alertAction) },
        String(row.approval_status || '').toLowerCase() === 'pending' && { label: 'Approve upload', icon: 'verified', onClick: () => runContentRowAction('approve', row, api, navigate, onRefresh, confirmAction, alertAction) },
        { divider: true },
        { label: 'Delete video', icon: 'trash', danger: true, onClick: () => runContentRowAction('delete', row, api, navigate, onRefresh, confirmAction, alertAction) },
      ]} />] };
    })} />
  </>;
}

function LiveTable({ rows, navigate, api, onRefresh, confirmAction, alertAction }) {
  return <SimpleTable columns={['Stream', 'Streamer', 'Category', 'Status', 'Start Time', 'Viewers', 'Pre-flight / Health', 'Actions']} rows={rows.map((row) => [<LiveStreamCell row={row} />, <PersonCell person={row.streamer} mini />, <Tag label={row.category} tone={categoryTone(row.category)} />, <span className={`admin-live-dot status-${String(row.status_tone || '').replace(/[^a-z0-9_-]/gi, '')}`}><i />{row.status}</span>, splitLines(row.start), row.viewers, <HealthCell value={row.health} />, <AdminRowActions label={row.title || 'stream'} actions={[
    { label: 'View stream', icon: 'external', onClick: () => runLiveRowAction('view', row, api, navigate, onRefresh, confirmAction, alertAction) },
    { label: 'Find reports', icon: 'reports', onClick: () => runLiveRowAction('reports', row, api, navigate, onRefresh, confirmAction, alertAction) },
    { label: 'Live settings', icon: 'settings', onClick: () => runLiveRowAction('settings', row, api, navigate, onRefresh, confirmAction, alertAction) },
    { divider: true },
    { label: 'Delete live stream', icon: 'trash', danger: true, onClick: () => runLiveRowAction('delete', row, api, navigate, onRefresh, confirmAction, alertAction) },
  ]} />])} />;
}

function ReportsTable({ rows, navigate, api, onRefresh, confirmAction, alertAction, compact = false, highlightId = '' }) {
  const [selectedKeys, setSelectedKeys] = useState([]);
  const keyFor = (row) => `${String(row.type).toLowerCase()}:${row.video_id || row.comment_id || row.user_id || row.id}`;
  const visibleKeys = rows.map(keyFor);
  const allSelected = visibleKeys.length > 0 && visibleKeys.every((key) => selectedKeys.includes(key));
  const someSelected = visibleKeys.some((key) => selectedKeys.includes(key)) && !allSelected;
  useEffect(() => setSelectedKeys((keys) => keys.filter((key) => visibleKeys.includes(key))), [visibleKeys.join(',')]);
  useEffect(() => {
    if (compact) return undefined;
    const startBulkActions = (event) => {
      event.detail.count = visibleKeys.length;
      setSelectedKeys(visibleKeys);
    };
    window.addEventListener('playhub:reports-bulk-actions', startBulkActions);
    return () => window.removeEventListener('playhub:reports-bulk-actions', startBulkActions);
  }, [compact, visibleKeys.join(',')]);
  const toggleAll = () => setSelectedKeys((keys) => allSelected ? keys.filter((key) => !visibleKeys.includes(key)) : Array.from(new Set([...keys, ...visibleKeys])));
  const toggleOne = (key) => setSelectedKeys((keys) => keys.includes(key) ? keys.filter((value) => value !== key) : [...keys, key]);
  const resolveSelected = async () => {
    const selectedRows = rows.filter((row) => selectedKeys.includes(keyFor(row)));
    if (!selectedRows.length) return;
    const confirmed = await confirmAction?.({ title: 'Resolve selected reports?', message: `Dismiss reports for ${selectedRows.length} selected item${selectedRows.length === 1 ? '' : 's'}?`, confirmLabel: 'Resolve selected' });
    if (!confirmed) return;
    try {
      await Promise.all(selectedRows.map((row) => api.request(`${adminReportPath(row)}/dismiss`, { method: 'POST' })));
      setSelectedKeys([]);
      onRefresh?.();
    } catch (error) {
      await alertAction?.({ title: 'Resolve failed', message: error.message || 'The selected reports could not be resolved.', tone: 'danger' });
    }
  };
  const columns = compact ? ['Report Type', 'Reported Item', 'Reason', 'Priority', 'Status', 'Actions'] : [<CheckBox checked={allSelected} indeterminate={someSelected} onChange={toggleAll} label="Select all reports" />, 'Report Type', 'Reported Item', 'Reporter', 'Reason', 'Priority', 'Moderator', 'Status', 'Actions'];
  const mapped = rows.map((row) => {
    const rowId = row.id ? `report-${String(row.type || 'item').toLowerCase()}-${row.id}` : '';
    const isProfile = String(row.type).toLowerCase() === 'profile';
    const actions = <AdminRowActions label={row.title || 'report'} actions={[
      { label: 'Review item', icon: 'external', onClick: () => runReportRowAction('view', row, api, navigate, onRefresh, confirmAction, alertAction) },
      { label: 'Dismiss reports', icon: 'verified', onClick: () => runReportRowAction('dismiss', row, api, navigate, onRefresh, confirmAction, alertAction) },
      !isProfile && { divider: true },
      !isProfile && { label: `Delete ${String(row.type).toLowerCase() === 'video' ? 'video' : 'comment'}`, icon: 'trash', danger: true, onClick: () => runReportRowAction('delete', row, api, navigate, onRefresh, confirmAction, alertAction) },
    ]} />;
    return { id: rowId, highlight: highlightId === rowId, cells: compact
      ? [<Tag label={row.type} tone="blue" />, <ContentCell row={{ title: row.title, caption: row.subtitle, thumb: row.thumb, thumbnail_url: row.thumbnail_url, avatar: isProfile }} mini />, row.reason, <PriorityPill label={row.priority} />, <StatusPill label={row.status} />, actions]
      : [<CheckBox checked={selectedKeys.includes(keyFor(row))} onChange={() => toggleOne(keyFor(row))} label={`Select ${row.title || 'report'}`} />, <Tag label={row.type} tone="blue" />, <ContentCell row={{ title: row.title, caption: row.subtitle, thumb: row.thumb, thumbnail_url: row.thumbnail_url, avatar: isProfile }} mini />, splitLines(row.reporter), row.reason, <PriorityPill label={row.priority} />, <PersonCell person={row.moderator} mini avatarOnlyName />, <StatusPill label={row.status} />, actions] };
  });
  return <>{!compact && !!selectedKeys.length && <div className="admin-selection-bar"><span>{selectedKeys.length} report{selectedKeys.length === 1 ? '' : 's'} selected</span><div className="admin-selection-actions"><button type="button" onClick={resolveSelected}><AdminIcon name="verified" size={15}/>Resolve selected</button><button type="button" className="muted" onClick={() => setSelectedKeys([])}>Clear selection</button></div></div>}<SimpleTable columns={columns} rows={mapped} /></>;
}

function SupportTable({ rows, navigate, api, onRefresh, alertAction, highlightId = '', onOpenTicket = null }) {
  const [selectedIds, setSelectedIds] = useState([]);
  const visibleIds = rows.map((row) => row.raw_id).filter(Boolean);
  const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedIds.includes(id));
  const someSelected = visibleIds.some((id) => selectedIds.includes(id)) && !allSelected;
  useEffect(() => setSelectedIds((ids) => ids.filter((id) => visibleIds.includes(id))), [visibleIds.join(',')]);
  const toggleAll = () => setSelectedIds((ids) => allSelected ? ids.filter((id) => !visibleIds.includes(id)) : Array.from(new Set([...ids, ...visibleIds])));
  const toggleOne = (id) => setSelectedIds((ids) => ids.includes(id) ? ids.filter((value) => value !== id) : [...ids, id]);
  const bulkStatus = async (status) => {
    if (!selectedIds.length) return;
    try {
      await Promise.all(selectedIds.map((id) => api.request(`/admin/support/contact-messages/${id}/status`, { method: 'PUT', body: JSON.stringify({ status }) })));
      setSelectedIds([]);
      onRefresh?.();
      await alertAction?.({ title: 'Tickets updated', message: `${selectedIds.length} support ticket${selectedIds.length === 1 ? '' : 's'} updated.` });
    } catch (error) {
      await alertAction?.({ title: 'Update failed', message: error.message || 'The selected tickets could not be updated.', tone: 'danger' });
    }
  };
  return <>{!!selectedIds.length && <div className="admin-selection-bar"><span>{selectedIds.length} ticket{selectedIds.length === 1 ? '' : 's'} selected</span><div className="admin-selection-actions"><button type="button" onClick={() => bulkStatus('in_progress')}>Mark in progress</button><button type="button" onClick={() => bulkStatus('waiting')}>Waiting on user</button><button type="button" onClick={() => bulkStatus('resolved')}><AdminIcon name="verified" size={15}/>Resolve</button><button type="button" className="muted" onClick={() => setSelectedIds([])}>Clear selection</button></div></div>}<SimpleTable columns={[<CheckBox checked={allSelected} indeterminate={someSelected} onChange={toggleAll} label="Select all support tickets" />, 'Ticket ID', 'Subject', 'User', 'Category', 'Priority', 'Assigned Agent', 'Last Update', 'SLA Status', 'Actions']} rows={rows.map((row) => {
    const rowId = row.raw_id ? `support-${row.raw_id}` : String(row.id || '').toLowerCase().replace(/^#?/, 'support-');
    const openTicket = () => row.raw_id && onOpenTicket ? onOpenTicket(row) : runSupportRowAction('open', row, api, navigate, onRefresh, alertAction);
    return { id: rowId, highlight: highlightId === rowId, cells: [row.raw_id ? <CheckBox checked={selectedIds.includes(row.raw_id)} onChange={() => toggleOne(row.raw_id)} label={`Select ${row.subject || 'ticket'}`} /> : <span/>, <button type="button" className="admin-linkish admin-table-link-button" onClick={openTicket}>{row.id}</button>, <button type="button" className="admin-table-link-button admin-ticket-subject-button" onClick={openTicket}><TwoLine title={row.subject} subtitle={row.preview} /></button>, <PersonCell person={row.user} mini showEmail />, <Tag label={row.category} tone="slate" />, <PriorityPill label={row.priority} />, <PersonCell person={row.agent || { name: 'Unassigned', handle: 'Unassigned', tone: 'mono' }} mini avatarOnlyName />, row.updated, <StatusPill label={row.sla} />, <AdminRowActions label={row.subject || 'ticket'} actions={[
      { label: 'Open ticket', icon: 'external', onClick: openTicket },
      String(row.category || '').toLowerCase().includes('moderation') && { label: 'Open reports', icon: 'reports', onClick: () => runSupportRowAction('reports', row, api, navigate, onRefresh, alertAction) },
      row.raw_id && row.sla !== 'Open' && { label: 'Mark open', icon: 'refresh', onClick: () => runSupportRowAction('status:open', row, api, navigate, onRefresh, alertAction) },
      row.raw_id && row.sla !== 'In Progress' && { label: 'Mark in progress', icon: 'clock', onClick: () => runSupportRowAction('status:in_progress', row, api, navigate, onRefresh, alertAction) },
      row.raw_id && row.sla !== 'Waiting on User' && { label: 'Waiting on user', icon: 'users', onClick: () => runSupportRowAction('status:waiting', row, api, navigate, onRefresh, alertAction) },
      row.raw_id && row.sla !== 'Resolved' && { label: 'Mark resolved', icon: 'verified', onClick: () => runSupportRowAction('resolve', row, api, navigate, onRefresh, alertAction) },
    ]} />] };
  })} /></>;
}

function RolesTable({ rows, navigate, api, data, onRefresh, confirmAction, alertAction, promptAction }) {
  return <SimpleTable columns={['Role Name', 'Members', 'Scope', 'Last Updated', 'Status', 'Actions']} rows={rows.map((row) => [<RoleCell row={row} />, <AvatarStack members={row.members} count={row.count} />, <Tag label={row.scope} tone={row.scope === 'Global' ? 'blue' : row.scope === 'Restricted' ? 'orange' : 'purple'} />, splitLines(row.updated), <StatusPill label={row.status} />, <AdminRowActions label={row.name || 'role'} actions={[
    !row.custom && { label: 'View users', icon: 'users', onClick: () => navigate?.(`/admin/users?role=${encodeURIComponent(row.id === 'super-admin' ? 'Admin' : row.name)}`) },
    row.custom && { label: 'Edit role', icon: 'edit', onClick: () => runRoleRowAction('edit', row, { api, data, onRefresh, confirmAction, alertAction, promptAction }) },
    row.custom && { label: 'Delete role', icon: 'trash', danger: true, onClick: () => runRoleRowAction('delete', row, { api, data, onRefresh, confirmAction, alertAction, promptAction }) },
    { label: 'Login security', icon: 'shield', onClick: () => navigate?.('/admin/system-settings?section=login&highlight=setting-login#setting-login') },
  ]} />])} />;
}

function PermissionMatrixTable({ rows }) {
  return <SimpleTable columns={['Role', 'Scope', 'Members', 'Permissions', 'Status']} rows={rows.map((row) => [<TwoLine title={row.role} subtitle={`${row.members || 0} member${Number(row.members) === 1 ? '' : 's'}`} />, <Tag label={row.scope} tone={row.scope === 'Global' ? 'blue' : row.scope === 'Restricted' ? 'orange' : 'purple'} />, row.members || 0, splitLines(row.permissionSummary || 'No permissions'), <StatusPill label={row.status || 'Active'} />])} />;
}

function PermissionGroupsTable({ rows, onEdit = null }) {
  return <SimpleTable columns={['Group', 'Permissions', 'Roles', 'Status', 'Actions']} rows={rows.map((row) => [<TwoLine title={row.name} subtitle={row.description || 'Reusable permission group'} />, splitLines(row.permissionSummary || 'No permissions'), `${row.roleCount || 0} role${Number(row.roleCount) === 1 ? '' : 's'}`, <StatusPill label={row.status || 'Active'} />, <AdminRowActions label={row.name || 'permission group'} actions={[{ label: 'Edit group', icon: 'edit', onClick: () => onEdit?.(row) }]} />])} />;
}

function PayoutTable({ rows, navigate, onDecision, busy = '' }) {
  return <SimpleTable columns={['Creator', 'Payout Method', 'Amount', 'Status', 'Request Date', 'Actions']} rows={rows.map((row) => [<PersonCell person={row.person} />, <span className="admin-method"><AdminIcon name={row.icon} size={24} /><span><strong>{row.method}</strong>{row.account && <small>{row.account}</small>}{row.note && <small>{row.note}</small>}</span></span>, row.amount, <StatusPill label={row.status} />, row.date, <AdminRowActions label={row.person?.name || 'payout'} actions={[
    row.status_key === 'pending' && { label: busy === `${row.id}:approved` ? 'Approving...' : 'Approve withdrawal', icon: 'verified', disabled: Boolean(busy), onClick: () => onDecision?.(row, 'approved') },
    row.status_key === 'pending' && { label: busy === `${row.id}:rejected` ? 'Rejecting...' : 'Reject withdrawal', icon: 'close', danger: true, disabled: Boolean(busy), onClick: () => onDecision?.(row, 'rejected') },
    { label: 'Payment settings', icon: 'monetization', onClick: () => navigate?.('/admin/system-settings?section=integrations&highlight=setting-integrations#setting-integrations') },
    { label: 'Find creator', icon: 'creators', onClick: () => navigate?.(`/admin/creators?search=${encodeURIComponent(row.person?.handle || row.person?.name || '')}`) },
  ]} />])} />;
}

function scrollAdminPaginationContentIntoView(source) {
  if (typeof window === 'undefined') return;
  window.setTimeout(() => {
    const container = source?.closest?.('.admin-table-panel, .admin-card, .admin-main-stack, main');
    const target = container?.querySelector?.('.admin-table-wrap, .admin-data-table, .admin-card-title-row') || container;
    target?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
  }, 80);
}

function TableFooter({ text, pages = [], activePage = 1, lastPage = null, onPageChange = null }) {
  const goToPage = (page, source = null) => {
    const numeric = Number(page);
    if (onPageChange && Number.isFinite(numeric) && numeric > 0) {
      onPageChange(numeric);
      scrollAdminPaginationContentIntoView(source);
    }
  };
  const maxPage = lastPage || Math.max(...pages.map((page) => Number(page) || 1), activePage);

  return <div className="admin-table-footer"><span>{text}</span><div className="admin-pagination"><button type="button" onClick={(event) => goToPage(activePage - 1, event.currentTarget)} disabled={!onPageChange || activePage <= 1}><AdminIcon name="chevronLeft" size={14} /></button>{pages.map((page, index) => <button type="button" className={Number(page) === activePage || (!onPageChange && index === 0) ? 'active' : ''} aria-current={Number(page) === activePage || (!onPageChange && index === 0) ? 'page' : undefined} key={`${page}-${index}`} onClick={(event) => goToPage(page, event.currentTarget)} disabled={!onPageChange}>{page}</button>)}<button type="button" onClick={(event) => goToPage(activePage + 1, event.currentTarget)} disabled={!onPageChange || activePage >= maxPage}><AdminIcon name="chevronRight" size={14} /></button></div></div>;
}

function SideCard({ title, action, children, className = '', onAction = null }) {
  return <article className={`admin-card admin-side-card ${className}`}><div className="admin-card-title-row"><h2>{title}</h2>{action && <button type="button" className="admin-text-button" onClick={onAction} disabled={!onAction}>{action}</button>}</div>{children}</article>;
}

function ProgressList({ rows, max = null, compact = false }) {
  return <div className={`admin-progress-list ${compact ? 'compact' : ''}`}>{rows.map((row) => {
    const numeric = parseFloat(String(row.value).replace(/[^0-9.]/g, '')) || 0;
    const width = max ? (numeric / max) * 100 : numeric;
    return <div className="admin-progress-row" key={row.label}><span>{row.icon && <AdminIcon name={row.icon} size={17} />}{row.label}</span><strong>{row.value}</strong><i><b style={{ width: `${Math.min(width, 100)}%`, backgroundColor: adminVisualColor(row.color) }} /></i></div>;
  })}</div>;
}

function InsightList({ items }) {
  return <div className="admin-insight-list">{items.map(([icon, text]) => <div key={text}><AdminIcon name={icon} size={18} /><span>{text}</span></div>)}</div>;
}

function QuickActionList({ actions, onAction = null }) {
  return <SideCard title="Quick Actions"><div className="admin-quick-actions">{actions.map((action) => <button type="button" key={action} onClick={() => onAction?.(action)} disabled={!onAction}><span>{actionIcon(action)}</span><b>{action.replace(/\s+\d+$/, '')}</b>{/\d+$/.test(action) && <em>{action.match(/\d+$/)?.[0]}</em>}<AdminIcon name="chevronRight" size={15} /></button>)}</div></SideCard>;
}

function actionIcon(action) {
  if (/export|download/i.test(action)) return <AdminIcon name="download" size={16} />;
  if (/schedule|cron|calendar/i.test(action)) return <AdminIcon name="calendar" size={16} />;
  if (/settings|manage/i.test(action)) return <AdminIcon name="settings" size={16} />;
  if (/report|flag|moderation|guidelines/i.test(action)) return <AdminIcon name="shield" size={16} />;
  if (/verify|invite|user|member/i.test(action)) return <AdminIcon name="users" size={16} />;
  return <AdminIcon name="target" size={16} />;
}

function LegendList({ rows, compact = false }) {
  return <div className={`admin-legend-list ${compact ? 'compact' : ''}`}>{rows.map(([label, value, color]) => <div key={label}><span><i style={{ backgroundColor: adminVisualColor(color) }} />{label}</span><strong>{value}</strong></div>)}</div>;
}

function RankedPeople({ rows, numbered = false }) {
  return <div className="admin-ranked-list">{rows.map((row, index) => <div key={row.person.handle || row.person.name} className="admin-ranked-row">{numbered && <b>{index + 1}</b>}<PersonCell person={row.person} mini /> <strong>{row.value}</strong>{row.delta && <em>{row.delta}</em>}</div>)}</div>;
}

function PeopleOnline({ rows, footer }) {
  return <div className="admin-online-list">{rows.map((person) => <div key={person.email || person.name}><PersonCell person={person} mini showEmail={!person.detail} subtitle={person.detail} /><i /></div>)}{footer && <small><i />{footer}</small>}</div>;
}

function ActivityList({ rows }) {
  return <div className="admin-activity-list">{rows.length ? rows.map(([person, action, time], index) => <div key={`${person.name}-${action}-${index}`}><PersonCell person={person} mini subtitle={action} />{time && <em>{time}</em>}</div>) : <p className="admin-activity-empty">No admin changes recorded yet.</p>}</div>;
}

function KeyValueList({ rows }) {
  return <div className="admin-kv-list">{rows.map(([label, value]) => <div key={label}><span>{label}</span><strong>{value}</strong></div>)}</div>;
}

function KeyValueIconList({ rows }) {
  return <div className="admin-kv-icon-list">{rows.map(([label, value, icon = 'clock']) => <div key={label}><span><AdminIcon name={icon} size={16} />{label}</span><strong>{value}</strong></div>)}</div>;
}

function ServerStatus({ rows = null, status = 'Status data unavailable', onStatusPage = null }) {
  const items = rows || ['Web Server', 'API Server', 'Storage', 'Cache', 'Media Storage', 'CDN'].map((item) => [item, 'No data']);
  return <div className="admin-server-status"><strong><i />{status}</strong>{items.map(([label, value]) => <div key={label}><span><i />{label}</span><em>{value}</em></div>)}<button type="button" className="admin-text-button align-left" onClick={onStatusPage} disabled={!onStatusPage}>View Status Page <AdminIcon name="external" size={14} /></button></div>;
}

function HealthList({ health = null }) {
  const items = health?.rows || ['API Uptime', 'Storage', 'Queue', 'Backups'].map((item) => [item, 'No data']);
  return <div className="admin-server-status mini"><strong><i />{health?.status || 'Health data unavailable'}</strong>{items.map(([label, value]) => <div key={label}><span><i />{label}</span><em>{value}</em></div>)}</div>;
}

function ScheduleList({ rows }) {
  return <div className="admin-schedule-list">{rows.map(([person, title, category, time]) => <div key={title}><AdminAvatar name={person.name} tone={person.tone} /><span><strong>{title}</strong><small>{category}</small></span><em>{time}</em></div>)}</div>;
}

function UploadList({ rows = [] }) {
  return <div className="admin-upload-list">{rows.map((row) => <div key={row.title}><Thumb name={row.thumb} src={row.thumbnail_url || row.thumbnailUrl} compact /><span><strong>{row.title}</strong></span><em>{row.uploaded || row.active || row.date || 'Upload date unavailable'}</em></div>)}</div>;
}

function TransactionList({ rows = [] }) {
  const items = rows;
  return <div className="admin-transaction-list">{items.length ? items.map(([title, name, amount, date, icon]) => <div key={`${title}-${name}`}><span><i><AdminIcon name={icon} size={16} /></i><b>{title}</b><small>{name}</small></span><strong>{amount}<em>{date}</em></strong></div>) : <div><span><i><AdminIcon name="monetization" size={16} /></i><b>No transactions yet</b><small>Payment records are not configured.</small></span><strong>$0<em>No records</em></strong></div>}</div>;
}

function SettingsRow({ id = '', visible = true, highlight = false, title, description, children, collapsible = false, open = true, onToggle = null }) {
  if (!visible) return null;
  const isOpen = !collapsible || open;
  const controlsId = id ? `${id}-controls` : undefined;
  const header = <><span><h2>{title}</h2><p>{description}</p></span>{collapsible && <em>{isOpen ? 'Hide' : 'Open'}<i aria-hidden="true" /></em>}</>;

  return <section id={id || undefined} className={`admin-settings-row ${highlight ? 'admin-row-highlight' : ''} ${collapsible ? `is-collapsible ${isOpen ? 'is-open' : 'is-collapsed'}` : ''}`}>
    {collapsible ? <button type="button" className="admin-settings-copy admin-settings-row-toggle" onClick={onToggle} aria-expanded={isOpen} aria-controls={controlsId}>{header}</button> : <div className="admin-settings-copy">{header}</div>}
    {collapsible ? <div id={controlsId} className="admin-settings-controls-shell" aria-hidden={!isOpen}><div className="admin-settings-controls">{children}</div></div> : <div id={controlsId} className="admin-settings-controls">{children}</div>}
  </section>;
}

function Field({ label, value, suffix }) {
  return <label className="admin-field"><span>{label}</span><div><input value={value} readOnly />{suffix && <em>{suffix}</em>}</div></label>;
}

function SelectField({ label, value, wide = false }) {
  return <label className={`admin-field ${wide ? 'wide' : ''}`}><span>{label}</span><div><input value={value} readOnly /></div></label>;
}

function ThemeModeField({ value = 'dark', onChange, saved = false }) {
  return <div className={`admin-theme-mode-field ${saved ? 'saved' : ''}`}><span>Theme</span><div className="admin-theme-segmented" role="group" aria-label="Theme">
    {['light', 'dark', 'system'].map((mode) => <button type="button" key={mode} className={value === mode ? 'active' : ''} onClick={() => onChange(mode)}>{mode.replace(/^./, (char) => char.toUpperCase())}</button>)}
  </div></div>;
}

function AccentPaletteField({ value = '', onChange, saved = false }) {
  return <div className={`admin-accent-palette-field ${saved ? 'saved' : ''}`}><span>Accent color</span><div className="admin-accent-palette-list">
    {adminAccentPalettes.map(([key, palette]) => <button type="button" key={key} className={value === key ? 'active' : ''} style={{ '--swatch': palette.swatch || palette.primary, '--swatch-2': palette.primary2 }} onClick={() => onChange(key)} aria-label={`${palette.label || key} design skin`} title={palette.label || key} />)}
  </div></div>;
}

function StorageSecretField({ label, value, configured, clear, onChange, onClear, wide = false, disabled = false }) {
  const { t } = useI18n();
  return <label className={`admin-field ${wide ? 'wide' : ''}`} data-i18n-skip><span>{t(label)}</span><div>
    <input type="password" autoComplete="new-password" disabled={disabled || clear} style={{ minWidth: 0 }} value={value} placeholder={t(clear ? 'Will be cleared on save' : configured ? 'Configured — leave blank to keep' : 'Not configured')} onChange={(event) => onChange(event.target.value)} />
    {(configured || clear) && <button type="button" disabled={disabled} style={{ width: 'auto', flex: '0 0 auto' }} onClick={onClear} title={t(clear ? 'Keep the saved secret' : 'Remove the saved secret when settings are saved')}>{t(clear ? 'Undo clear' : 'Remove credential')}</button>}
  </div></label>;
}

function StorageHelp({ children }) {
  return <p style={{ gridColumn: '1 / -1', margin: 0, color: 'var(--ad-settings-copy)', fontSize: 12, lineHeight: 1.6, overflowWrap: 'anywhere' }}>{children}</p>;
}

function EditableField({ label, value, onChange, type = 'text', wide = false, saved = false, step, suffix = '', showColorPicker = false, placeholder = '', disabled = false, required = false, min, max, maxLength, pattern }) {
  const hasValidColor = typeof value === 'string' && /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(value.trim());
  const swatchValue = showColorPicker ? (hasValidColor ? value : '#000000') : '#000000';

  return <label className={`admin-field ${wide ? 'wide' : ''} ${saved ? 'saved' : ''}`}><span>{label}</span><div>
    <input type={type} step={step} value={value} placeholder={placeholder} disabled={disabled} required={required} min={min} max={max} maxLength={maxLength} pattern={pattern} onChange={(event) => onChange(event.target.value)} />
    {showColorPicker && <input type="color" className="admin-color-swatch" value={swatchValue} onChange={(event) => onChange(event.target.value)} title="Pick color" />}
    {suffix && <em>{suffix}</em>}
  </div></label>;
}

function EditableTextarea({ label, value, onChange, saved = false, placeholder = '', suffix = '', code = false }) {
  return <label className={`admin-field admin-field-textarea wide ${code ? 'code' : ''} ${saved ? 'saved' : ''}`}><span>{label}</span><div><textarea value={value} placeholder={placeholder} spellCheck={code ? false : undefined} onChange={(event) => onChange(event.target.value)} />{suffix && <em>{suffix}</em>}</div></label>;
}

function EditableSelect({ label, value, onChange, options, saved = false, wide = false, disabled = false }) {
  return <label className={`admin-field ${wide ? 'wide' : ''} ${saved ? 'saved' : ''}`}><span>{label}</span><div><AdaptiveSelect value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)}>{options.map(([optionValue, optionLabel]) => <option key={optionValue} value={optionValue}>{optionLabel}</option>)}</AdaptiveSelect></div></label>;
}

function EditableToggleField({ label, checked = false, onChange, saved = false, disabled = false }) {
  const { t } = useI18n();
  return <label className={`admin-toggle-field admin-toggle-edit ${saved ? 'saved' : ''}`}><span>{label}</span><button type="button" aria-pressed={checked} onClick={() => onChange(!checked)} disabled={disabled}><i className={checked ? 'active' : ''} /><em>{t(checked ? 'Enabled' : 'Disabled')}</em></button></label>;
}

function EditableCheckboxField({ label, checked = false, onChange, saved = false }) {
  return <label className={`admin-checkbox-field ${saved ? 'saved' : ''}`}><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} /><span>{label}</span></label>;
}

function ToggleField({ label, value, active = false }) {
  return <label className="admin-toggle-field"><span>{label}</span><div><i className={active ? 'active' : ''} /><em>{value}</em></div></label>;
}

function IntegrationBadge({ icon, name, status, active = false, wide = false, onClick = null, disabled = false }) {
  return <button type="button" className={`admin-integration ${active ? 'active' : ''} ${wide ? 'wide' : ''}`} onClick={onClick} aria-pressed={active} disabled={disabled}><AdminIcon name={icon} size={24} /><span><strong>{name}</strong><small>{status}</small></span></button>;
}

function PersonCell({ person = null, mini = false, showEmail = false, avatarOnlyName = false, subtitle = null }) {
  const safePerson = person || { name: 'Unknown', handle: 'Unknown', email: '', tone: 'mono' };
  return <div className={`admin-person-cell ${mini ? 'mini' : ''}`}><AdminAvatar name={safePerson.name} image={safePerson.avatar_url || safePerson.avatarUrl} tone={safePerson.tone} /><span><strong><span>{safePerson.name}</span>{safePerson.verified && <em className="admin-profile-verified-badge" title="Verified" aria-label="Verified"><svg viewBox="0 0 12 12" width="13" height="13" fill="currentColor" role="img"><title>Verified account</title><path d="M7.198.34a.88.88 0 0 1 1.164.38l.623 1.175 1.311.23a.88.88 0 0 1 .72.99l-.187 1.318.925.956a.88.88 0 0 1 0 1.224l-.925.957.186 1.317a.88.88 0 0 1-.719.99l-1.31.23-.625 1.176a.88.88 0 0 1-1.163.378l-1.196-.584-1.196.584a.88.88 0 0 1-1.164-.378l-.623-1.176-1.311-.23a.88.88 0 0 1-.72-.99l.187-1.317-.925-.957a.88.88 0 0 1 0-1.224l.925-.956-.187-1.318a.88.88 0 0 1 .72-.99l1.31-.23L3.643.72A.88.88 0 0 1 4.806.34l1.196.584L7.198.341zm1.656 4.764a.5.5 0 1 0-.708-.708L5.25 7.293 3.854 5.896a.5.5 0 1 0-.708.708L4.543 8a1 1 0 0 0 1.414 0l2.897-2.896z" /></svg></em>}</strong>{!avatarOnlyName && <small>{subtitle || (showEmail ? safePerson.email : safePerson.handle)}</small>}</span></div>;
}

function ContentCell({ row, mini = false }) {
  const safeRow = row || { title: 'Untitled', caption: '', thumb: 'default' };
  return <div className={`admin-content-cell ${mini ? 'mini' : ''}`}>{safeRow.avatar ? <AdminAvatar name={safeRow.title} image={safeRow.thumbnail_url || safeRow.thumbnailUrl} /> : <Thumb name={safeRow.thumb} src={safeRow.thumbnail_url || safeRow.thumbnailUrl} compact={mini} />}<span><strong>{safeRow.title}</strong>{safeRow.caption && <small>{safeRow.caption}</small>}</span></div>;
}

function LiveStreamCell({ row }) {
  return <div className="admin-live-cell"><Thumb name={row.thumb} src={row.thumbnail_url || row.thumbnailUrl} live /><span><strong>{row.title}</strong><small>{row.lang}</small></span></div>;
}

function HealthCell({ value }) {
  const [speed, health] = String(value).split('\n');
  return <span className={`admin-health-cell ${slugify(health)}`}><strong>{speed}</strong><small><i />{health}</small></span>;
}

function RoleCell({ row }) {
  return <div className="admin-role-cell"><span className="admin-role-icon"><AdminIcon name={row.icon || 'shield'} size={18} /></span><span><strong>{row.name}</strong><small>{row.desc}</small></span></div>;
}

function AvatarStack({ members = [], count }) {
  return <div className="admin-avatar-stack">{members.slice(0, 3).map((member) => <AdminAvatar key={member.name} name={member.name} tone={member.tone} />)}<strong>{count}</strong></div>;
}

function TwoLine({ title, subtitle }) {
  return <span className="admin-two-line"><strong>{title}</strong><small>{subtitle}</small></span>;
}

function StatusPill({ label }) {
  return <span className={`admin-status-pill status-${slugify(label)}`}>{label}</span>;
}

function PriorityPill({ label }) {
  return <span className={`admin-priority-pill priority-${slugify(label)}`}>{label}</span>;
}

function Tag({ label, tone = 'slate' }) {
  return <span className={`admin-tag tone-${tone}`}>{label}</span>;
}

function TrendText({ children, direction = 'up' }) {
  const down = direction === 'down' || String(children).startsWith('-');
  return <span className={`admin-trend-text ${down ? 'down' : ''}`}><AdminIcon name={down ? 'trendDown' : 'trendUp'} size={13} />{children}</span>;
}

function AdminRowActions({ label = 'row', actions = [] }) {
  const [open, setOpen] = useState(false);
  const [menuStyle, setMenuStyle] = useState(null);
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const visibleActions = actions.filter(Boolean);

  const toggleMenu = () => {
    if (open) {
      setOpen(false);
      return;
    }
    const rect = buttonRef.current?.getBoundingClientRect();
    if (rect && typeof window !== 'undefined') {
      const width = 264;
      const height = Math.min(430, 24 + visibleActions.reduce((total, action) => total + (action.divider ? 20 : 42), 0));
      const spaceBelow = window.innerHeight - rect.bottom - 12;
      const spaceAbove = rect.top - 12;
      const opensUp = spaceBelow < height && spaceAbove > spaceBelow;
      setMenuStyle({
        left: `${Math.max(12, Math.min(rect.right - width, window.innerWidth - width - 12))}px`,
        top: `${opensUp ? Math.max(12, rect.top - height - 10) : Math.min(rect.bottom + 10, window.innerHeight - height - 12)}px`,
      });
    }
    setOpen(true);
  };

  useEffect(() => {
    if (!open) return undefined;
    const close = () => setOpen(false);
    const closeOnOutside = (event) => {
      if (buttonRef.current?.contains(event.target) || menuRef.current?.contains(event.target)) return;
      close();
    };
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') close();
    };
    document.addEventListener('mousedown', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);
    document.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
    return () => {
      document.removeEventListener('mousedown', closeOnOutside);
      document.removeEventListener('keydown', closeOnEscape);
      document.removeEventListener('scroll', close, true);
      window.removeEventListener('resize', close);
    };
  }, [open]);

  return <span className="admin-user-actions">
    <button type="button" ref={buttonRef} className={`admin-more-button ${open ? 'active' : ''}`} aria-label={`Open actions for ${label}`} aria-haspopup="menu" aria-expanded={open} onClick={toggleMenu}><AdminIcon name="more" size={16} /></button>
    {open && <div ref={menuRef} className="admin-row-action-menu" role="menu" style={menuStyle || undefined}>
      {visibleActions.map((action, index) => action.divider
        ? <i aria-hidden="true" key={`divider-${index}`} />
        : <button type="button" role="menuitem" className={action.danger ? 'danger' : action.soft ? 'is-soft' : ''} key={action.label} disabled={action.disabled} onClick={async () => { if (action.disabled) return; setOpen(false); await action.onClick?.(); }}><AdminIcon name={action.icon || 'target'} size={20} /><span>{action.label}</span></button>)}
    </div>}
  </span>;
}

function CheckBox({ checked = false, indeterminate = false, onChange = null, label = 'Select row' }) {
  const ref = useRef(null);

  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate;
  }, [indeterminate]);

  if (!onChange) return <span className="admin-checkbox" aria-hidden="true" />;

  return <input ref={ref} type="checkbox" className="admin-checkbox" checked={checked} onChange={onChange} aria-label={label} />;
}

function splitLines(value) {
  return <span className="admin-split-lines">{String(value).split('\n').map((line) => <React.Fragment key={line}>{line}<br /></React.Fragment>)}</span>;
}

function Thumb({ name = 'default', src = '', live = false, compact = false }) {
  return <span className={`admin-thumb thumb-${name} ${src ? 'has-image' : ''} ${compact ? 'compact' : ''}`}>{src && <img src={src} alt="" onError={(event) => { event.currentTarget.style.display = 'none'; }} />}{live && <b>LIVE</b>}</span>;
}

function AdminAvatar({ name, image = null, tone = 'violet' }) {
  return <span className={`admin-avatar admin-avatar-${tone || 'violet'} ${image ? '' : 'default-avatar'}`.trim()} aria-hidden="true">{image ? <img src={image} alt="" /> : <svg className="admin-avatar-default-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="m 8 1 c -1.65625 0 -3 1.34375 -3 3 s 1.34375 3 3 3 s 3 -1.34375 3 -3 s -1.34375 -3 -3 -3 z m -1.5 7 c -2.492188 0 -4.5 2.007812 -4.5 4.5 v 0.5 c 0 1.109375 0.890625 2 2 2 h 8 c 1.109375 0 2 -0.890625 2 -2 v -0.5 c 0 -2.492188 -2.007812 -4.5 -4.5 -4.5 z m 0 0" /></svg>}</span>;
}

function adminAssetUrl(value) {
  if (!value) return '';
  if (/^(https?:)?\/\//.test(value) || String(value).startsWith('/')) return value;
  return `/uploads/${String(value).replace(/^public\//, '')}`;
}

function adminWebsiteBrand() {
  const website = typeof window !== 'undefined' && window.__PLAYHUB_WEBSITE__ ? window.__PLAYHUB_WEBSITE__ : {};
  return {
    text: String(website.logoText ?? website.platformName ?? 'PlayHub').trim(),
    showText: website.showLogoText !== false,
    size: Math.max(16, Math.min(64, Number(website.logoSize) || 40)),
    name: website.platformName || 'PlayHub',
    logoUrl: adminAssetUrl(website.logoUrl),
  };
}

function isAdminSvgLogoUrl(value) {
  return /\.svg(?:$|[?#])/i.test(String(value || ''));
}

function adminLogoMaskStyle(value) {
  return { '--logo-url': `url("${String(value || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"')}")` };
}

function AdminLogo() {
  const brand = adminWebsiteBrand();
  const svgLogo = isAdminSvgLogoUrl(brand.logoUrl);
  return (
    <span className="admin-logo-wrap" role="img" aria-label={brand.name} style={{ '--website-logo-size': `${brand.size}px` }}>
      <span className={`admin-logo-mark ${svgLogo ? 'has-svg-logo' : ''}`} aria-hidden="true">
        {svgLogo ? <span className="admin-logo-svg-mask" style={adminLogoMaskStyle(brand.logoUrl)} /> : brand.logoUrl ? <img className="admin-logo-image" src={brand.logoUrl} alt="" /> : <AdminIcon name="play" size={18} />}
      </span>
      {brand.showText && brand.text && <span className="admin-logo-text">{brand.text}</span>}
    </span>
  );
}

function AdminIcon({ name, size = 20 }) {
  const paths = {
    dashboard: <><path d="M4 11.5 12 5l8 6.5" /><path d="M6.5 10.5V20h11V10.5" /><path d="M10 20v-5h4v5" /></>,
    users: <><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20" /><circle cx="9.5" cy="7.5" r="3.5" /><path d="M21 20v-1.2a3.2 3.2 0 0 0-2.5-3.1" /><path d="M16.5 4.4a3.3 3.3 0 0 1 0 6.2" /></>,
    creators: <><circle cx="9" cy="8" r="3.5" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0" /><path d="M17 8h4" /><path d="M19 6v4" /><path d="M18 14a4 4 0 0 1 3 3.8V20" /></>,
    content: <><rect x="5" y="4" width="14" height="16" rx="2" /><path d="M9 8h6M9 12h6M9 16h4" /></>,
    live: <><path d="M7.2 8.3a5.4 5.4 0 0 0 0 7.4" /><path d="M16.8 8.3a5.4 5.4 0 0 1 0 7.4" /><circle cx="12" cy="12" r="2.2" /></>,
    gift: <><path d="M4 10h16v10H4z" /><path d="M12 10v10M3 7h18v3H3z" /><path d="M12 7H8.5a2.5 2.5 0 1 1 2.5-2.5L12 7Zm0 0h3.5A2.5 2.5 0 1 0 13 4.5L12 7Z" /></>,
    sticker: <><rect x="4" y="4" width="16" height="16" rx="5"/><path d="M8.5 10h.01M15.5 10h.01"/><path d="M8 14c1.1 1.2 2.4 1.8 4 1.8s2.9-.6 4-1.8"/></>,
    reports: <><path d="M7 4h10l3 3v13H7z" /><path d="M17 4v4h4" /><path d="M10 13h4M10 17h7" /></>,
    monetization: <><circle cx="12" cy="12" r="9" /><path d="M15 9.5a3 3 0 0 0-3-1.3c-1.7 0-3 .8-3 2s1.2 1.8 3 2 3 .7 3 2-1.3 2-3 2a3.6 3.6 0 0 1-3.2-1.5" /><path d="M12 6.5v11" /></>,
    campaign: <><path d="m3 11 18-5v12L3 13v-2Z" /><path d="M11.6 15.4 13 21H8l-1.6-7" /><path d="M21 10v4" /></>,
    analytics: <><path d="M4 20V5M4 20h16" /><rect x="7" y="12" width="2.8" height="5" rx="1" /><rect x="11" y="8" width="2.8" height="9" rx="1" /><rect x="15" y="5" width="2.8" height="12" rx="1" /></>,
    support: <><circle cx="12" cy="12" r="9" /><path d="M9.4 9.2a2.8 2.8 0 1 1 5.1 1.6c-.8 1.1-2.1 1.3-2.3 2.8" /><path d="M12 17h.01" /></>,
    shield: <><path d="M12 3.5 19 6v5.3c0 4.2-2.8 7.8-7 9.2-4.2-1.4-7-5-7-9.2V6l7-2.5Z" /><path d="m9.5 12 1.7 1.7 3.4-3.8" /></>,
    globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18" /><path d="M12 3a14 14 0 0 1 0 18" /><path d="M12 3a14 14 0 0 0 0 18" /></>,
    language: <><path d="M4 5h8M8 3v2c0 4-1.8 7.2-5 9" /><path d="M5 10c1.4 2.1 3.2 3.5 5.5 4.2" /><path d="m14 20 3.5-9 3.5 9M15.3 17h4.4" /></>,
    plugin: <><path d="M8.5 4.5A2.5 2.5 0 1 1 13 6h4a2 2 0 0 1 2 2v3.5a2.5 2.5 0 1 1 0 4V19a2 2 0 0 1-2 2h-4.5a2.5 2.5 0 1 0-5 0H4a2 2 0 0 1-2-2v-3.5a2.5 2.5 0 1 0 0-5V8a2 2 0 0 1 2-2h4.5a2.5 2.5 0 0 1 0-1.5Z" /></>,
    settings: <><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2 2-.1-.1a1.8 1.8 0 0 0-2-.3 1.7 1.7 0 0 0-1 1.5v.2h-3v-.2a1.7 1.7 0 0 0-1-1.5 1.8 1.8 0 0 0-2 .3l-.1.1-2-2 .1-.1A1.7 1.7 0 0 0 7 15a1.7 1.7 0 0 0-1.5-1H5.3v-3h.2A1.7 1.7 0 0 0 7 10a1.7 1.7 0 0 0-.3-1.9l-.1-.1 2-2 .1.1a1.8 1.8 0 0 0 2 .3 1.7 1.7 0 0 0 1-1.5v-.2h3v.2a1.7 1.7 0 0 0 1 1.5 1.8 1.8 0 0 0 2-.3l.1-.1 2 2-.1.1A1.7 1.7 0 0 0 17 10a1.7 1.7 0 0 0 1.5 1h.2v3h-.2a1.7 1.7 0 0 0-1.5 1Z" /></>,
    search: <><circle cx="11" cy="11" r="6.5" /><path d="m20 20-3.5-3.5" /></>,
    enter: <><path d="M20 4v7a4 4 0 0 1-4 4H5" /><path d="m9 11-4 4 4 4" /></>,
    bell: <><path d="M18 8a6 6 0 0 0-12 0c0 6.5-2.7 7.2-2.7 9h17.4C20.7 15.2 18 14.5 18 8" /><path d="M10 21h4" /></>,
    calendar: <><path d="M8 3v3M16 3v3" /><rect x="4" y="5" width="16" height="15" rx="2" /><path d="M4 10h16" /></>,
    download: <><path d="M12 4v10" /><path d="m8 10 4 4 4-4" /><path d="M5 20h14" /></>,
    userPlus: <><circle cx="9" cy="8" r="3.5" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0" /><path d="M18 8h4M20 6v4" /></>,
    play: <path d="M8 5v14l11-7-11-7Z" />,
    chevronDown: <path d="m7 10 5 5 5-5" />,
    chevronLeft: <path d="m15 6-6 6 6 6" />,
    chevronRight: <path d="m9 6 6 6-6 6" />,
    menu: <><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" /></>,
    close: <><path d="m6 6 12 12" /><path d="M18 6 6 18" /></>,
    plus: <><path d="M12 5v14" /><path d="M5 12h14" /></>,
    more: <><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /><circle cx="5" cy="12" r="1" /></>,
    trendUp: <><path d="m5 14 4-4 3 3 6-7" /><path d="M14 6h4v4" /></>,
    trendDown: <><path d="m5 10 4 4 3-3 6 7" /><path d="M14 18h4v-4" /></>,
    eye: <><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" /><circle cx="12" cy="12" r="3" /></>,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    heart: <path d="M20.5 8.8c0 5.5-8.5 10.2-8.5 10.2S3.5 14.3 3.5 8.8A4.5 4.5 0 0 1 12 6.7a4.5 4.5 0 0 1 8.5 2.1Z" />,
    target: <><circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="3" /></>,
    activity: <><path d="M4 17 9 12l3 3 7-8" /><path d="M4 20h16" /></>,
    edit: <><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z" /><path d="m14 7 3 3" /></>,
    key: <><circle cx="7.5" cy="14.5" r="3.5" /><path d="m10 12 8-8" /><path d="m15 7 2 2" /><path d="m13 9 2 2" /></>,
    filter: <><path d="M4 6h16" /><path d="M7 12h10" /><path d="M10 18h4" /></>,
    refresh: <><path d="M20 12a8 8 0 1 1-2.4-5.7" /><path d="M20 4v6h-6" /></>,
    mobile: <><rect x="8" y="3" width="8" height="18" rx="2" /><path d="M11 18h2" /></>,
    desktop: <><rect x="4" y="5" width="16" height="11" rx="2" /><path d="M8 21h8M12 16v5" /></>,
    tablet: <rect x="7" y="3" width="10" height="18" rx="2" />,
    tv: <><rect x="4" y="7" width="16" height="11" rx="2" /><path d="M9 21h6M12 18v3" /></>,
    youtube: <><rect x="3" y="7" width="18" height="10" rx="3" /><path d="m10 10 5 2-5 2v-4Z" /></>,
    google: <><circle cx="12" cy="12" r="8" /><path d="M12 12h8" /></>,
    facebook: <path d="M15 8h-2a2 2 0 0 0-2 2v2H9v3h2v6h3v-6h2.4l.6-3h-3v-1.5c0-.8.3-1.5 1.4-1.5H17V6.2c-.4-.1-1-.2-2-.2Z" />,
    instagram: <><rect x="5" y="5" width="14" height="14" rx="4" /><circle cx="12" cy="12" r="3" /><path d="M16 8h.01" /></>,
    link: <><path d="M10 13a5 5 0 0 0 7.1 0l1.4-1.4a5 5 0 0 0-7.1-7.1L10.5 5" /><path d="M14 11a5 5 0 0 0-7.1 0L5.5 12.4a5 5 0 0 0 7.1 7.1l.9-.9" /></>,
    escalation: <><path d="M12 3 3 20h18L12 3Z" /><path d="M12 9v4M12 17h.01" /></>,
    warning: <><path d="M12 3 3 20h18L12 3Z" /><path d="M12 9v4M12 17h.01" /></>,
    pauseCircle: <><circle cx="12" cy="12" r="9" /><path d="M10 8v8M14 8v8" /></>,
    trash: <><path d="M4 7h16" /><path d="M10 11v6M14 11v6" /><path d="M6 7l1 14h10l1-14" /><path d="M9 7V4h6v3" /></>,
    flag: <><path d="M5 21V5" /><path d="M5 5h11l-1 4 1 4H5" /></>,
    game: <><rect x="4" y="8" width="16" height="9" rx="4" /><path d="M8 12h4M10 10v4" /><path d="M16 12h.01M18 14h.01" /></>,
    music: <><path d="M9 18V6l10-2v12" /><circle cx="7" cy="18" r="3" /><circle cx="17" cy="16" r="3" /></>,
    science: <><path d="M9 3h6" /><path d="M10 3v5l-5 9a3 3 0 0 0 2.6 4.5h8.8A3 3 0 0 0 19 17l-5-9V3" /></>,
    ban: <><circle cx="12" cy="12" r="9" /><path d="m5.8 5.8 12.4 12.4" /></>,
    ad: <><rect x="4" y="6" width="16" height="12" rx="2" /><path d="M8 15V9l4 6 4-6v6" /></>,
    crown: <><path d="m4 8 4 4 4-7 4 7 4-4-2 11H6L4 8Z" /></>,
    storage: <><ellipse cx="12" cy="6" rx="7" ry="3" /><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6" /><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6" /></>,
    mail: <><rect x="4" y="6" width="16" height="12" rx="2" /><path d="m4 8 8 5 8-5" /></>,
    cloud: <><path d="M17.5 18H8a4 4 0 1 1 .7-7.9A5.5 5.5 0 0 1 19 12a3 3 0 0 1-1.5 6Z" /><path d="M12 12v7" /><path d="m9 16 3-3 3 3" /></>,
    external: <><path d="M14 5h5v5" /><path d="m10 14 9-9" /><path d="M19 14v5H5V5h5" /></>,
    resolved: <><circle cx="12" cy="12" r="9" /><path d="m8.5 12.5 2.4 2.4 4.8-5.3" /></>,
    paypal: <path d="M7 20 10 4h6.4c3.2 0 4.7 1.6 4.2 4.5-.5 3-2.7 4.9-6 4.9h-2.4L11 20H7Z" />,
    bank: <><path d="M4 10h16L12 4 4 10Z" /><path d="M6 10v8M10 10v8M14 10v8M18 10v8M4 20h16" /></>,
    payoneer: <circle cx="12" cy="12" r="8" />,
    stripe: <path d="M8 17c1.2.7 2.8 1 4.4 1 2.6 0 4.6-1.2 4.6-3.4 0-2-1.4-2.8-4-3.5-1.5-.4-2-.7-2-1.3 0-.7.6-1.1 1.8-1.1 1.2 0 2.5.3 3.6.9V6.5A8.2 8.2 0 0 0 12.7 6C10 6 8.1 7.4 8.1 9.5c0 1.9 1.4 2.8 3.7 3.4 1.7.5 2.2.8 2.2 1.5 0 .7-.7 1.1-1.9 1.1-1.4 0-2.8-.4-4.1-1.1V17Z" />,
    sendgrid: <><rect x="4" y="4" width="7" height="7" /><rect x="13" y="4" width="7" height="7" /><rect x="4" y="13" width="7" height="7" /><rect x="13" y="13" width="7" height="7" /></>,
    verified: <><circle cx="12" cy="12" r="9" /><path d="m8.5 12.2 2.3 2.3 4.8-5" /></>,
    sun: <><circle cx="12" cy="12" r="4" /><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" /></>,
    moon: <path d="M21 13A8 8 0 1 1 11 3a6.5 6.5 0 0 0 10 10Z" />,
    feedback: <><path d="M5 5h14v10H8l-3 3V5Z" /><path d="M8 9h8M8 12h5" /></>,
    switchAccount: <><circle cx="8.5" cy="8" r="3" /><path d="M3.5 19a5 5 0 0 1 8.8-3.2" /><path d="M16 7h4v4" /><path d="M20 7l-5.5 5.5" /><path d="M18.5 17H14v-4.5" /><path d="M14 17l5.5-5.5" /></>,
    logout: <><path d="M10 5H5v14h5" /><path d="M14 8l4 4-4 4" /><path d="M18 12H9" /></>,
    copyright: <><circle cx="12" cy="12" r="9" /><path d="M15 9.5a4 4 0 1 0 0 5" /></>,
  };

  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {paths[name] || paths.dashboard}
    </svg>
  );
}

function getPolylinePoints(points, width, height, padding = 0) {
  const max = Math.max(...points, 1);
  const min = Math.min(...points, 0);
  return points.map((point, index) => {
    const x = padding + (index / Math.max(points.length - 1, 1)) * (width - padding * 2);
    const y = height - padding - ((point - min) / Math.max(max - min, 1)) * (height - padding * 2);
    return `${x.toFixed(2)},${y.toFixed(2)}`;
  }).join(' ');
}

function getChartCoordinates(points, width, height, padding, min, max) {
  return points.map((point, index) => {
    const x = padding + (index / Math.max(points.length - 1, 1)) * (width - padding * 2);
    const y = height - padding - ((point - min) / Math.max(max - min, 1)) * (height - padding * 2);
    return [Number(x.toFixed(2)), Number(y.toFixed(2))];
  });
}

function coordsToPath(coords) {
  return coords.map(([x, y], index) => `${index === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ');
}

function buildConicGradient(items) {
  const total = items.reduce((sum, [, value]) => sum + value, 0) || 1;
  let cursor = 0;
  const stops = items.map(([color, value]) => {
    const start = cursor;
    cursor += (value / total) * 100;
    return `${color} ${start.toFixed(2)}% ${cursor.toFixed(2)}%`;
  });
  return `conic-gradient(${stops.join(', ')})`;
}

function slugify(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function categoryTone(category) {
  const c = String(category).toLowerCase();
  if (c.includes('gaming') || c.includes('science')) return 'purple';
  if (c.includes('music') || c.includes('health') || c.includes('food')) return 'pink';
  if (c.includes('education') || c.includes('tech') || c.includes('travel')) return 'blue';
  if (c.includes('lifestyle') || c.includes('art')) return 'orange';
  return 'green';
}

export default AdminDashboard;
