import React from 'react';

export function LogoMark({ size = 42, className = '' }) {
  return (
    <div
      className={`brand-mark-wrapper ${className}`}
      style={{ width: `${size}px`, height: `${size}px` }}
      aria-hidden="true"
    >
      <img
        src="/logo.png"
        alt="مجتمع آموزشی دکتر هدایتی"
        className="site-logo"
        referrerPolicy="no-referrer"
      />
    </div>
  );
}

export default function Logo({ compact = false, size = 42 }) {
  return (
    <div className={`brand-logo ${compact ? 'compact' : ''}`} aria-label="مجتمع آموزشی دکتر هدایتی">
      <LogoMark size={size} />
      {!compact && (
        <span className="brand-copy">
          <b>دکتر هدایتی</b>
          <small>مجتمع آموزشی تخصصی</small>
        </span>
      )}
    </div>
  );
}

