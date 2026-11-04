import React, { forwardRef, useEffect, useMemo, useState } from 'react';
import Select from 'react-select';

export const shouldUseNativeSelect = () => {
  if (typeof window === 'undefined') return false;
  const browser = window.navigator;
  const mobileBrowser = browser.userAgentData?.mobile === true
    || /Android|iPhone|iPad|iPod|Mobile/i.test(browser.userAgent || '')
    || (browser.platform === 'MacIntel' && browser.maxTouchPoints > 1);
  return mobileBrowser;
};

const readOptions = (children) => React.Children.toArray(children).flatMap((child) => {
  if (!React.isValidElement(child)) return [];
  if (child.type === React.Fragment) return readOptions(child.props.children);
  if (child.type === 'optgroup') {
    return [{ label: child.props.label, options: readOptions(child.props.children) }];
  }
  if (child.type !== 'option') return [];
  return [{
    value: child.props.value ?? child.props.children,
    label: child.props.children,
    isDisabled: Boolean(child.props.disabled),
  }];
});

const flattenOptions = (options) => options.flatMap((option) => Array.isArray(option.options) ? option.options : [option]);

const optionLabelText = (label) => React.Children.toArray(label).map((part) => {
  if (typeof part === 'string' || typeof part === 'number') return String(part);
  return React.isValidElement(part) ? optionLabelText(part.props.children) : '';
}).join('');

const AdaptiveSelect = forwardRef(function AdaptiveSelect({
  children,
  className = '',
  value,
  defaultValue,
  onChange,
  onBlur,
  onFocus,
  disabled = false,
  required = false,
  name,
  id,
  placeholder = 'Select an option',
  searchable = false,
  ...rest
}, ref) {
  const [useNative, setUseNative] = useState(shouldUseNativeSelect);
  const [portalTarget, setPortalTarget] = useState(null);
  const optionTree = useMemo(() => readOptions(children), [children]);
  const optionList = useMemo(() => flattenOptions(optionTree), [optionTree]);
  const menuContentWidth = useMemo(() => {
    const labels = optionList.map((option) => optionLabelText(option.label));
    if (typeof document === 'undefined') return Math.max(160, ...labels.map((label) => label.length * 8 + 52));
    const context = document.createElement('canvas').getContext('2d');
    if (!context) return Math.max(160, ...labels.map((label) => label.length * 8 + 52));
    context.font = `700 13px ${window.getComputedStyle(document.body).fontFamily || 'sans-serif'}`;
    return Math.max(160, ...labels.map((label) => Math.ceil(context.measureText(label).width) + 52));
  }, [optionList]);
  const firstOption = optionList.find((option) => !option.isDisabled);
  const [internalValue, setInternalValue] = useState(() => defaultValue ?? firstOption?.value ?? '');
  const controlled = value !== undefined;
  const currentValue = controlled ? value : internalValue;
  const selectedOption = optionList.find((option) => String(option.value) === String(currentValue)) || null;
  const emptyRequiredOption = required && String(currentValue ?? '') === '' ? selectedOption : null;

  useEffect(() => {
    if (typeof window === 'undefined') return undefined;
    const media = window.matchMedia('(any-pointer: coarse), (hover: none) and (pointer: coarse)');
    const updateInputMode = () => setUseNative(shouldUseNativeSelect());
    updateInputMode();
    // The body remains connected while route preloading swaps page shells.
    setPortalTarget(document.body);
    if (media.addEventListener) {
      media.addEventListener('change', updateInputMode);
      return () => media.removeEventListener('change', updateInputMode);
    }
    media.addListener(updateInputMode);
    return () => media.removeListener(updateInputMode);
  }, []);

  useEffect(() => {
    if (!controlled && !selectedOption && firstOption) setInternalValue(firstOption.value);
  }, [controlled, firstOption, selectedOption]);

  if (useNative) {
    return <select
      {...rest}
      ref={ref}
      id={id}
      name={name}
      className={`adaptive-native-select ${className}`.trim()}
      value={controlled ? value : undefined}
      defaultValue={controlled ? undefined : defaultValue}
      disabled={disabled}
      required={required}
      onChange={onChange}
      onBlur={onBlur}
      onFocus={onFocus}
    >{children}</select>;
  }

  const dispatchChange = (option) => {
    const nextValue = option?.value == null ? '' : String(option.value);
    if (!controlled) setInternalValue(nextValue);
    const target = { name, value: nextValue };
    onChange?.({ target, currentTarget: target, type: 'change' });
  };

  return <Select
    ref={ref}
    inputId={id}
    name={name}
    className={`adaptive-select-root ${className}`.trim()}
    classNamePrefix="adaptive-select"
    unstyled
    value={emptyRequiredOption ? null : selectedOption}
    options={optionTree}
    onChange={dispatchChange}
    onBlur={onBlur}
    onFocus={onFocus}
    isDisabled={disabled}
    isSearchable={searchable}
    isOptionDisabled={(option) => Boolean(option.isDisabled)}
    required={required}
    placeholder={emptyRequiredOption?.label || placeholder}
    aria-label={rest['aria-label']}
    aria-labelledby={rest['aria-labelledby']}
    aria-describedby={rest['aria-describedby']}
    aria-invalid={rest['aria-invalid']}
    menuPosition="fixed"
    menuPortalTarget={portalTarget}
    styles={{ menuPortal: (base) => {
      if (typeof window === 'undefined') return { ...base, zIndex: 5000 };
      const viewportPadding = 12;
      const maximumWidth = Math.max(0, window.innerWidth - viewportPadding * 2);
      const controlWidth = Number.parseFloat(base.width) || 0;
      const menuWidth = Math.min(maximumWidth, Math.max(controlWidth, menuContentWidth));
      const controlLeft = Number.parseFloat(base.left) || viewportPadding;
      const left = Math.max(viewportPadding, Math.min(controlLeft, window.innerWidth - menuWidth - viewportPadding));
      return { ...base, left, width: menuWidth, zIndex: 5000 };
    } }}
  />;
});

export default AdaptiveSelect;
