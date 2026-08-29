import React, { useState } from 'react';
import { ArrowLeft, CheckCircle2, ChevronLeft, Menu, PhoneCall, Star, ShieldCheck, X } from 'lucide-react';
import Logo from './Logo';
import { brand, categories, stats } from '../data/siteData';

export function Header({ navigate }) {
  const [open, setOpen] = useState(false);
  const go = path => { navigate(path); setOpen(false); };
  return (
    <header className="site-header" dir="rtl">
      <div className="container header-inner">
        <div onClick={() => go('home')} style={{ cursor: 'pointer' }}><Logo /></div>
        <nav className={open ? 'open' : ''}>
          <button onClick={() => go('home')}>صفحه اصلی</button>
          <button onClick={() => go('courses')}>دوره‌ها</button>
          <button onClick={() => go('certificates')}>گواهینامه‌ها</button>
          <button onClick={() => go('about')}>درباره مجتمع</button>
          <button onClick={() => go('blog')}>مطالب آموزشی</button>
          <button onClick={() => go('contact')}>تماس</button>
        </nav>
        <div className="header-cta">
          <button className="outline-btn" onClick={() => go('consult')}><PhoneCall size={16}/><span>مشاوره ثبت‌نام</span></button>
          <button className="solid-btn" onClick={() => go('student')}>ورود دانشجو</button>
        </div>
        <button className="mobile-menu" onClick={() => setOpen(!open)} aria-label="منو">{open ? <X size={22}/> : <Menu size={22}/>}</button>
      </div>
    </header>
  );
}

export function Footer({ navigate }) {
  return (
    <footer className="site-footer" dir="rtl">
      <div className="container footer-grid">
        <div className="footer-brand">
          <Logo />
          <p>{brand.name} با بیش از ۲۰ سال سابقه تخصصی در برگزاری دوره‌های فناوری اطلاعات، شبکه، برنامه‌نویسی، پایگاه‌های داده و مهارت‌های کاربردی بازار کار.</p>
        </div>
        <div>
          <h4>دسترسی سریع</h4>
          <button onClick={() => navigate('courses')}>دوره‌های آموزشی</button>
          <button onClick={() => navigate('certificates')}>استعلام گواهینامه</button>
          <button onClick={() => navigate('about')}>درباره مجتمع</button>
          <button onClick={() => navigate('contact')}>تماس با ما</button>
        </div>
        <div>
          <h4>دپارتمان‌ها</h4>
          {categories.slice(0, 4).map(c => (
            <button key={c.id} onClick={() => navigate(`courses:${c.id}`)}>{c.title}</button>
          ))}
        </div>
        <div>
          <h4>تماس با ما</h4>
          <p>تلفن مشاوره و ثبت‌نام: {brand.registerPhone}</p>
          <p>تلفن تبریز: {brand.phone}</p>
          <p>تلفن تهران: {brand.tehranPhone}</p>
          <p>{brand.tabrizAddress}</p>
        </div>
      </div>
      <div className="container copyright">
        <span>© {brand.name} — کلیه حقوق محفوظ است.</span>
        <span>طرح بازطراحی وب‌سایت</span>
      </div>
    </footer>
  );
}

export function CourseCard({ course, navigate }) {
  return (
    <article className="course-card" onClick={() => navigate(`course:${course.slug}`)}>
      <div className="course-topline">
        <span className="course-index-tag">{course.category.toUpperCase()}</span>
        <span className="course-english-tag">{course.english}</span>
      </div>
      <div className="course-art">
        <div className="course-art-bg" />
        <span className="course-monogram">{course.english.slice(0, 3)}</span>
        <div className="course-rating-badge"><Star size={12} fill="#ffb703" color="#ffb703"/> <span>۴.۹</span></div>
        <div className="course-cert-chip"><ShieldCheck size={12}/> <span>مدرک معتبر</span></div>
      </div>
      <div className="course-body">
        <div className="course-meta">
          <span className="meta-pill">{course.level}</span>
          <span className="meta-pill">{course.duration}</span>
        </div>
        <h3>{course.title}</h3>
        <p>{course.summary}</p>
        <div className="course-tags">
          {(course.tags || []).slice(0, 3).map((tag, i) => (
            <span key={i} className="tech-tag">{tag}</span>
          ))}
        </div>
        <div className="course-footer">
          <span className="seats-badge"><i className="pulse-dot"></i> {course.seats || 'ثبت‌نام باز'}</span>
          <span className="card-action-btn">مشاهده دوره <ArrowLeft size={14}/></span>
        </div>
      </div>
    </article>
  );
}

export function CategoryStrip({ onSelect }) {
  return (
    <div className="category-strip">
      {categories.map(c => (
        <button key={c.id} onClick={() => onSelect(c.id)}>
          <span className="cat-icon">{c.icon}</span>
          <span><b>{c.title}</b><small>{c.english}</small></span>
          <ChevronLeft size={16}/>
        </button>
      ))}
    </div>
  );
}

export function RedesignedImpactSection({ navigate }) {
  return (
    <section className="impact-section redesigned-impact" dir="rtl">
      <div className="container impact-grid">
        <div className="impact-copy">
          <span className="eyebrow light">کیفیت و نتیجه آموزش</span>
          <h2>آموزش هدفمند، مسیر شفاف، نتیجه قابل اتکا</h2>
          <p>
            تفاوت مجتمع آموزشی دکتر هدایتی در حذف حواشی و تمرکز روی مهارت‌هایی است که در پروژه‌ها، مصاحبه‌های فنی
            و بازار کار واقعی از شما انتظار می‌رود.
          </p>
          <div className="impact-points">
            <span><CheckCircle2 size={16}/> اساتید باتجربه بازار کار</span>
            <span><CheckCircle2 size={16}/> کارگاه‌های مجهز و عملی</span>
            <span><CheckCircle2 size={16}/> پشتیبانی آموزشی در طول دوره</span>
            <span><CheckCircle2 size={16}/> گواهینامه معتبر پایان دوره</span>
          </div>
          <button className="white-btn" onClick={() => navigate('about')}>آشنایی بیشتر با مجتمع <ArrowLeft size={16}/></button>
        </div>
        <div className="stats-grid">
          {stats.map((s, i) => (
            <div key={i}><strong>{s.value}</strong><span>{s.label}</span></div>
          ))}
        </div>
      </div>
    </section>
  );
}

export function FeaturedCoursesGrid({ courses, navigate }) {
  const featured = courses.filter(c => c.featured && c.published).slice(0, 8);
  return (
    <section className="section featured-showcase" dir="rtl">
      <div className="container">
        <div className="section-heading row">
          <div>
            <span>دوره‌های منتخب</span>
            <h2>مسیرهای پیشنهادی برای شروع یا ارتقای مهارت</h2>
            <p>مجموعه‌ای از دوره‌های پرتقاضای شبکه، برنامه‌نویسی، هوش مصنوعی، طراحی و مهارت‌های پایه.</p>
          </div>
          <button className="outline-btn" onClick={() => navigate('courses')}>مشاهده همه دوره‌ها <ArrowLeft size={16}/></button>
        </div>
        <div className="featured-course-grid">
          {featured.map(c => (
            <CourseCard key={c.id} course={c} navigate={navigate}/>
          ))}
        </div>
      </div>
    </section>
  );
}

export function CtaBand({ navigate }) {
  return (
    <section className="cta-band" dir="rtl">
      <div className="container">
        <div>
          <span>نیاز به راهنمایی دارید؟</span>
          <h2>مشاوره رایگان برای انتخاب دوره و شروع مسیر یادگیری</h2>
        </div>
        <button onClick={() => navigate('consult')}>درخواست تماس مشاوره <ArrowLeft size={16}/></button>
      </div>
    </section>
  );
}
