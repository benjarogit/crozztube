const sameOwner = (first, second) => Boolean(first && second && first.id === second.id && first.epoch === second.epoch);
const validOwner = owner => Boolean(owner && Number.isSafeInteger(owner.id) && owner.id > 0 && Number.isSafeInteger(owner.epoch) && owner.epoch >= 0);
const empty = () => ({ owner: null, status: null, visible: false, loading: false, accepting: false, error: false });

export function readWebSafetyStatus(value) {
  const policy = value?.policy; const consent = value?.consent;
  if (!policy || !consent || typeof policy.available !== 'boolean' || !/^[a-f0-9]{64}$/.test(policy.version)
    || typeof policy.terms_url !== 'string' || typeof policy.privacy_url !== 'string'
    || typeof consent.required !== 'boolean' || typeof consent.accepted !== 'boolean'
    || !(consent.accepted_version === null || typeof consent.accepted_version === 'string')) throw new Error('Invalid safety status.');
  const accepted = policy.available && consent.accepted && !consent.required && consent.accepted_version === policy.version;
  const unavailableDocuments = Array.isArray(policy.unavailable_documents)
    ? policy.unavailable_documents.filter(key => ['terms', 'privacy'].includes(key)) : [];
  return { policy: { version: policy.version, available: policy.available, terms_url: policy.terms_url, privacy_url: policy.privacy_url,
    unavailable_documents: unavailableDocuments },
    consent: { accepted, required: consent.required && !accepted, accepted_version: consent.accepted_version } };
}

export function webPolicyLinks(policy, origin) {
  const legal = (value, path) => {
    try {
      if (typeof value !== 'string' || value.length > 2048) return undefined;
      const url = new URL(value); const site = new URL(origin);
      if (!['https:', 'http:'].includes(url.protocol) || url.origin !== site.origin || url.username || url.password || url.search || url.hash || url.pathname !== path) return undefined;
      return url.toString();
    } catch { return undefined; }
  };
  return { terms: legal(policy?.terms_url, '/terms'), privacy: legal(policy?.privacy_url, '/privacy') };
}

/** Compatibility helper for voluntary legal receipts; the website does not mount it. */
export function createWebUgcConsent({ request, getOwner, origin, onChange = () => {} }) {
  let state = empty(); let generation = 0; let visibility = 0; let pending;
  const emit = next => { state = next; onChange(state); };
  const current = owner => validOwner(owner) && sameOwner(owner, state.owner) && sameOwner(owner, getOwner());
  const options = (owner, extra = {}) => ({ trackPageLoading: false, expectedAuthEpoch: owner.epoch, ...extra });
  const controller = {
    snapshot: () => state,
    owns: owner => current(owner),
    synchronize() {
      const candidate = getOwner(); const owner = validOwner(candidate) ? { id: candidate.id, epoch: candidate.epoch } : null;
      if (sameOwner(owner, state.owner) || (!owner && !state.owner)) return;
      generation++; visibility++; pending?.abort();
      emit({ ...empty(), owner });
      if (owner) void controller.refresh(true);
    },
    dismiss() { visibility++; emit({ ...state, visible: false }); },
    required(epoch) {
      if (!current(state.owner) || epoch !== state.owner.epoch) return;
      visibility++; emit({ ...state, status: null, visible: true, error: false });
      void controller.refresh(false);
    },
    async refresh(openIfRequired = false) {
      const owner = state.owner;
      if (!current(owner)) return;
      const ownGeneration = ++generation; const ownVisibility = visibility;
      pending?.abort(); const signal = new AbortController(); pending = signal;
      emit({ ...state, loading: true, accepting: false, error: false });
      try {
        // A changed token in another tab must not be accepted for a stale UI account.
        const identity = await request('/auth/me', options(owner, { signal: signal.signal }));
        if (!current(owner) || ownGeneration !== generation) return;
        if (identity?.user?.id !== owner.id) throw new Error('Session changed.');
        const status = readWebSafetyStatus(await request('/account/safety', options(owner, { signal: signal.signal })));
        if (!current(owner) || ownGeneration !== generation) return;
        const links = webPolicyLinks(status.policy, origin);
        const canReview = status.policy.available && Boolean(links.terms && links.privacy);
        emit({ ...state, status, loading: false, error: false,
          visible: status.consent.required && (state.visible || (openIfRequired && canReview && ownVisibility === visibility)) });
      } catch {
        if (current(owner) && ownGeneration === generation) emit({ ...state, loading: false, accepting: false, error: true });
      }
    },
    async accept(checked) {
      const owner = state.owner; const policy = state.status?.policy;
      const links = webPolicyLinks(policy, origin);
      if (checked !== true || !current(owner) || !policy?.available || !links.terms || !links.privacy || state.loading || state.accepting) return;
      const ownGeneration = ++generation;
      emit({ ...state, accepting: true, error: false });
      try {
        const status = readWebSafetyStatus(await request('/account/safety/consent', options(owner, {
          method: 'POST', body: JSON.stringify({ accepted: true, policy_version: policy.version }),
        })));
        if (!current(owner) || ownGeneration !== generation) return;
        if (!status.consent.accepted || status.policy.version !== policy.version) throw new Error('Policy changed.');
        emit({ ...state, status, accepting: false, visible: false, error: false });
      } catch {
        if (current(owner) && ownGeneration === generation) {
          emit({ ...state, accepting: false, error: true });
          // Reload a changed legal version; never repeat the attempted UGC write.
          await controller.refresh(false);
        }
      }
    },
    dispose() { generation++; visibility++; pending?.abort(); emit(empty()); },
  };
  return controller;
}
