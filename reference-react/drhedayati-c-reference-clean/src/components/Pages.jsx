import React, { useMemo, useState } from 'react';
import { ArrowLeft, CheckCircle2, ChevronRight, Clock, MapPin, Phone, Search, ShieldCheck, UserCheck, Calendar } from 'lucide-react';
import { Header, Footer, CourseCard } from './Common';
import { brand, categories } from '../data/siteData';

export function CoursesPage({ courses, navigate, category }) {
  const [query, setQuery] = useState('');
  const [activeCat, setActiveCat] = useState(category || 'all');

  const filtered = useMemo(() => {
    return courses.filter(c => {
      if (!c.published) return false;
      const matchesCat = activeCat === 'all' || c.category === activeCat;
      const matchesQuery = !query || c.title.includes(query) || c.english.toLowerCase().includes(query.toLowerCase()) || (c.tags || []).some(t => t.toLowerCase().includes(query.toLowerCase()));
      return matchesCat && matchesQuery;
    });
  }, [courses, activeCat, query]);

  return (
    <div className="inner-page" dir="rtl">
      <Header navigate={navigate}/>
      <section className="page-hero">
        <div className="container">
          <span className="eyebrow">دپارتمان‌های آموزشی</span>
          <h1>دوره‌های آموزشی تخصصی</h1>
          <p>دوره‌های پروژه‌محور با تمرکز بر مهارت‌های کاربردی و استاندارد برای ورود به بازار کار.</p>
        </div>
      </section>

      <section className="container course-browser">
        <div className="course-tools">
          <div className="search-box">
            <Search size={18}/>
            <input type="text" placeholder="جستجو بر اساس عنوان دوره، مهارت یا کلیدواژه..." value={query} onChange={e => setQuery(e.target.value)}/>
          </div>
          <div className="filter-row">
            <button className={activeCat === 'all' ? 'active' : ''} onClick={() => setActiveCat('all')}>همه دوره‌ها</button>
            {categories.map(c => (
              <button key={c.id} className={activeCat === c.id ? 'active' : ''} onClick={() => setActiveCat(c.id)}>{c.title}</button>
            ))}
          </div>
        </div>

        {filtered.length === 0 ? (
          <div className="empty-state">
            <p>دوره‌ای با این مشخصات یافت نشد.</p>
            <button className="solid-btn" onClick={() => { setQuery(''); setActiveCat('all'); }}>مشاهده همه دوره‌ها</button>
          </div>
        ) : (
          <div className="courses-grid">
            {filtered.map(c => <CourseCard key={c.id} course={c} navigate={navigate}/>)}
          </div>
        )}
      </section>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function CourseDetail({ course, navigate }) {
  if (!course) {
    return (
      <div className="not-found" dir="rtl">
        <h1>۴۰۴</h1>
        <p>دوره مورد نظر یافت نشد.</p>
        <button onClick={() => navigate('courses')}>بازگشت به لیست دوره‌ها</button>
      </div>
    );
  }

  return (
    <div className="course-detail-page" dir="rtl">
      <Header navigate={navigate}/>
      <section className="course-detail-hero">
        <div className="container detail-grid">
          <div>
            <div className="breadcrumb">
              <span onClick={() => navigate('courses')} style={{ cursor: 'pointer' }}>دوره‌ها</span>
              <ChevronRight size={14}/>
              <span>{course.english}</span>
            </div>
            <span className="eyebrow">{course.category.toUpperCase()}</span>
            <h1>{course.title}</h1>
            <p className="hero-lead">{course.summary}</p>
            <div className="tag-row">
              {(course.tags || []).map((t, i) => <span key={i}>{t}</span>)}
            </div>
          </div>
          <div className="course-info-panel">
            <div><Clock size={20}/><span>مدت دوره</span><b>{course.duration}</b></div>
            <div><UserCheck size={20}/><span>سطح دوره</span><b>{course.level}</b></div>
            <div><Calendar size={20}/><span>وضعیت شروع</span><b>{course.schedule}</b></div>
            <div><ShieldCheck size={20}/><span>گواهینامه</span><b>مدرک پایان دوره</b></div>
          </div>
        </div>
      </section>

      <section className="container section detail-content">
        <article>
          <h2>درباره این دوره</h2>
          <p>
            این دوره به صورت کاملاً کاربردی و با تکیه بر پروژه‌های واقعی طراحی شده است. تمرکز آموزش بر مهارت‌هایی است
            که بلافاصله پس از اتمام دوره در مصاحبه‌های فنی و پروژه‌های کاری قابل استفاده باشند.
          </p>
          <h2>سرفصل‌های آموزشی</h2>
          <div className="syllabus">
            <div><span>فصل ۱</span><b>مفاهیم پایه، معماری و راه‌اندازی محیط کاری</b><small>آشنایی با استانداردها و ابزارهای مورد نیاز</small></div>
            <div><span>فصل ۲</span><b>پیاده‌سازی سناریوهای کاربردی و تکنیک‌های پیشرفته</b><small>حل تمرین‌های عملی مرحله‌به‌مرحله</small></div>
            <div><span>فصل ۳</span><b>انجام پروژه پایانی و آماده‌سازی برای بازار کار</b><small>بررسی چالش‌های واقعی و نحوه پاسخ‌گویی به آنها</small></div>
          </div>
        </article>
        <aside className="sticky-register">
          <span>ثبت‌نام در این دوره</span>
          <h3>{course.title}</h3>
          <p>جهت کسب اطلاعات بیشتر، مشاوره اختصاصی و ثبت‌نام با کارشناسان ما تماس بگیرید.</p>
          <button className="solid-btn" onClick={() => navigate('consult')}>درخواست مشاوره و رزرو</button>
          <a href={`tel:${brand.registerPhone}`}><Phone size={16}/><span>{brand.registerPhone}</span></a>
        </aside>
      </section>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function AboutPage({ navigate }) {
  return (
    <div className="inner-page" dir="rtl">
      <Header navigate={navigate}/>
      <section className="page-hero">
        <div className="container">
          <span className="eyebrow">درباره ما</span>
          <h1>مجتمع آموزشی دکتر هدایتی</h1>
          <p>بیش از بیست سال همراهی مستمر با علاقه‌مندان به دنیای فناوری اطلاعات و ارتقای مهارت‌های تخصصی.</p>
        </div>
      </section>
      <section className="container section story-grid">
        <div className="story-number">۲۰<span>+</span><small>سال تجربه مستمر</small></div>
        <article>
          <h2>رسالت ما: مهارت واقعی، نه صرفاً مدرک</h2>
          <p>
            مجتمع آموزشی دکتر هدایتی از سال‌های نخستین توسعه فناوری اطلاعات در کشور، با هدف ارائه آموزش‌های
            کاربردی و استاندارد تاسیس گردید. در طول این مسیر، همواره تلاش کرده‌ایم با بهره‌گیری از اساتید صاحب‌تجربه،
            تجهیز کارگاه‌های عملی و به‌روزرسانی مداوم سرفصل‌ها، فاصله‌ی میان دانشگاه و بازار کار را به حداقل برسانیم.
          </p>
          <p>
            امروز هزاران دانش‌آموخته این مجتمع در شرکت‌های معتبر داخلی و بین‌المللی در موقعیت‌های شغلی کلیدی مشغول به فعالیت هستند.
          </p>
        </article>
      </section>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function CertificatesPage({ navigate }) {
  const [code, setCode] = useState('');
  const [searched, setSearched] = useState(false);

  return (
    <div className="inner-page" dir="rtl">
      <Header navigate={navigate}/>
      <section className="page-hero">
        <div className="container">
          <span className="eyebrow">اعتبارسنجی</span>
          <h1>استعلام آنلاین گواهینامه</h1>
          <p>جهت بررسی صحت و اصالت مدارک صادر شده از سوی مجتمع آموزشی دکتر هدایتی.</p>
        </div>
      </section>
      <section className="container section certificate-grid">
        <div className="verify-card">
          <ShieldCheck size={40}/>
          <span>سامانه اعتبارسنجی مدارک</span>
          <h2>کد گواهینامه را وارد نمایید</h2>
          <div className="verify-input">
            <input type="text" placeholder="مثال: DH-1404-2318" value={code} onChange={e => setCode(e.target.value)}/>
            <button onClick={() => setSearched(true)}>بررسی</button>
          </div>
          {searched && (
            <div className="demo-result">
              <CheckCircle2 size={24}/>
              <div>
                <b>گواهینامه تایید شده (نمونه)</b>
                <span>صادره به نام: دانشجوی نمونه — دوره: Python کاربردی</span>
              </div>
            </div>
          )}
        </div>
        <article>
          <h2>ویژگی‌های گواهینامه‌های مجتمع</h2>
          <p>کلیه گواهینامه‌ها پس از ارزیابی نهایی، آزمون عملی و بررسی پروژه‌های کلاسی صادر می‌گردند.</p>
          <div className="info-stack">
            <div><span>۰۱</span><b>دارای کد رهگیری یکتا و آنلاین</b><p>امکان استعلام فوری برای کارفرمایان</p></div>
            <div><span>۰۲</span><b>معتبر جهت ارائه در رزومه‌های کاری</b><p>مورد تایید سازمان‌ها و شرکت‌های فعال در حوزه IT</p></div>
            <div><span>۰۳</span><b>قابلیت ترجمه رسمی</b><p>مناسب برای پرونده‌های مهاجرتی و بین‌المللی</p></div>
          </div>
        </article>
      </section>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function BlogPage({ navigate }) {
  const articles = [
    { title: 'چرا یادگیری پایتون در سال ۲۰۲۶ همچنان هوشمندانه‌ترین انتخاب است؟', category: 'برنامه‌نویسی', summary: 'بررسی جایگاه پایتون در توسعه نرم‌افزار، دیتا ساینس و اتوماسیون سازمانی.' },
    { title: 'مسیر ورود به دنیای امنیت شبکه: از کجا شروع کنیم؟', category: 'امنیت', summary: 'راهنمای گام‌به‌گام از مبانی شبکه تا دوره‌های تخصصی تحلیل و دفاع سایبری.' },
    { title: 'تفاوت مدارک تخصصی سیسکو و نحوه انتخاب مسیر مناسب', category: 'شبکه', summary: 'بررسی جامع آزمون‌های CCNA و CCNP و ارزش آنها در بازار کار بین‌المللی.' },
    { title: 'مهارت‌های ضروری کار با کامپیوتر در عصر دیجیتال', category: 'پایه', summary: 'چرا تسلط بر مهارت‌های ICDL پیش‌نیاز هر حرفه و محیط اداری مدرن است؟' }
  ];

  return (
    <div className="inner-page" dir="rtl">
      <Header navigate={navigate}/>
      <section className="page-hero">
        <div className="container">
          <span className="eyebrow">وبلاگ و مقالات</span>
          <h1>مطالب و راهنماهای آموزشی</h1>
          <p>تحلیل‌های کاربردی، راهنمای مسیر شغلی و آخرین روندهای دنیای فناوری اطلاعات.</p>
        </div>
      </section>
      <section className="container section article-grid">
        {articles.map((a, i) => (
          <article key={i}>
            <span>{a.category}</span>
            <h3>{a.title}</h3>
            <p>{a.summary}</p>
            <button onClick={() => navigate('courses')}>مطالعه ادامه مطلب <ArrowLeft size={14}/></button>
          </article>
        ))}
      </section>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function ContactPage({ navigate }) {
  return (
    <div className="inner-page" dir="rtl">
      <Header navigate={navigate}/>
      <section className="page-hero">
        <div className="container">
          <span className="eyebrow">ارتباط با ما</span>
          <h1>تماس و شعب مجتمع</h1>
          <p>کارشناسان ما آماده پاسخگویی به سوالات و ارائه مشاوره تخصصی به شما هستند.</p>
        </div>
      </section>
      <section className="container section contact-grid">
        <div className="contact-card">
          <MapPin size={24}/>
          <span>شعبه مرکزی (تبریز)</span>
          <h3>{brand.tabrizAddress}</h3>
          <a href={`tel:${brand.phone}`}>{brand.phone}</a>
        </div>
        <div className="contact-card">
          <MapPin size={24}/>
          <span>دفتر تهران</span>
          <h3>{brand.tehranAddress}</h3>
          <a href={`tel:${brand.tehranPhone}`}>{brand.tehranPhone}</a>
        </div>
        <div className="contact-card accent">
          <Phone size={24}/>
          <span>مشاوره و ثبت‌نام سریع</span>
          <h3>تماس با واحد پذیرش</h3>
          <a href={`tel:${brand.registerPhone}`}>{brand.registerPhone}</a>
          <button onClick={() => navigate('consult')}>درخواست تماس از طرف ما <ArrowLeft size={16}/></button>
        </div>
      </section>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function ConsultPage({ navigate }) {
  const [submitted, setSubmitted] = useState(false);
  const handleSubmit = e => { e.preventDefault(); setSubmitted(true); };

  return (
    <div className="inner-page" dir="rtl">
      <Header navigate={navigate}/>
      <section className="page-hero">
        <div className="container">
          <span className="eyebrow">مشاوره اختصاصی</span>
          <h1>درخواست مشاوره و تعیین سطح رایگان</h1>
          <p>برای انتخاب بهترین دوره متناسب با هدف و سابقه خود، فرم زیر را تکمیل نمایید.</p>
        </div>
      </section>
      <section className="container section form-layout">
        <form onSubmit={handleSubmit}>
          <label><span>نام و نام خانوادگی</span><input required type="text" placeholder="مثال: علی محمدی"/></label>
          <label><span>شماره تماس</span><input required type="tel" placeholder="۰۹۱۲..."/></label>
          <label><span>حوزه مورد علاقه</span>
            <select>
              <option>شبکه و زیرساخت (Cisco / Network+)</option>
              <option>برنامه‌نویسی و پایتون</option>
              <option>امنیت سایبری</option>
              <option>هوش مصنوعی و دیتا</option>
              <option>ICDL و مهارت‌های پایه</option>
            </select>
          </label>
          <label><span>توضیحات یا سابقه قبلی (اختیاری)</span><textarea rows={4} placeholder="توضیح مختصری از هدف یا سوابق خود..."/></label>
          {submitted ? (
            <div className="success-message"><CheckCircle2 size={18}/><span>درخواست شما ثبت شد. کارشناسان ما به زودی با شما تماس خواهند گرفت.</span></div>
          ) : (
            <button type="submit" className="solid-btn">ارسال درخواست مشاوره</button>
          )}
        </form>
        <aside>
          <span>مشاوره تلفنی مستقیم</span>
          <h3>{brand.registerPhone}</h3>
          <p>ساعات پاسخگویی: شنبه تا چهارشنبه ۹ الی ۲۰، پنجشنبه‌ها ۹ الی ۱۴</p>
        </aside>
      </section>
      <Footer navigate={navigate}/>
    </div>
  );
}
