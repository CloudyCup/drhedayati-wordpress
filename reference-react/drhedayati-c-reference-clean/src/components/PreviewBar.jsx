import React from 'react';
import { Moon, Sun, LayoutDashboard, GraduationCap, Home, Layers3 } from 'lucide-react';

export default function PreviewBar({ concept, setConcept, theme, setTheme, navigate }) {
  const choose = id => { setConcept(id); navigate('home'); };
  return (
    <div className="preview-bar" dir="rtl">
      <div className="preview-title"><Layers3 size={16}/><span>نسخه ارائه به مدیریت</span></div>
      <div className="concept-tabs">
        <button className={concept === 'framework' ? 'active' : ''} onClick={() => choose('framework')}>A · چارچوب</button>
        <button className={concept === 'axis' ? 'active' : ''} onClick={() => choose('axis')}>B · محور</button>
        <button className={concept === 'navigator' ? 'active' : ''} onClick={() => choose('navigator')}>C · مسیر</button>
      </div>
      <div className="preview-actions">
        <button title="صفحه اصلی" onClick={() => navigate('home')}><Home size={16}/></button>
        <button title="پنل دانشجو" onClick={() => navigate('student')}><GraduationCap size={16}/><span>دانشجو</span></button>
        <button title="پنل مدیریت" onClick={() => navigate('admin')}><LayoutDashboard size={16}/><span>مدیریت</span></button>
        <button className="theme-toggle" onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')} title="تغییر تم">
          {theme === 'dark' ? <Sun size={16}/> : <Moon size={16}/>}<span>{theme === 'dark' ? 'روشن' : 'تیره'}</span>
        </button>
      </div>
    </div>
  );
}
