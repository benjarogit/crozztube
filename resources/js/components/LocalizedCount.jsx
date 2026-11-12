import React from 'react';
import { useI18n } from '../i18n';

export default function LocalizedCount({ value = 0, unit }) {
  const { count } = useI18n();
  return <span data-i18n-skip>{count(value, unit)}</span>;
}
