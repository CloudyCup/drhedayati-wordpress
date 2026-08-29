import React from 'react';
import { ArrowLeft, CheckCircle2, ChevronLeft, Compass, Cpu, Layers, ShieldCheck, Sparkles, Terminal, Award, Laptop, Code2 } from 'lucide-react';
import { Header, Footer, CategoryStrip, RedesignedImpactSection, FeaturedCoursesGrid, CtaBand } from './Common';

export function FrameworkHome({ courses, navigate }) {
  return (
    <div className="framework-home" dir="rtl">
      <Header navigate={navigate} />
      <section className="framework-hero">
        <div className="container framework-grid">
          <div className="framework-copy">
            <div className="status-pill"><span/><span>پذیرش ترم جدید فعال است</span></div>
            <p className="framework-kicker">مجتمع آموزشی دکتر هدایتی</p>
            <h1>مهارت <b>واقعی</b>، مسیر <b>شفاف</b> برای ورود به دنیای حرفه‌ای</h1>
            <p className="hero-lead">
              بیش از دو دهه تجربه در آموزش تخصصی شبکه، برنامه‌نویسی، پایگاه‌های داده و مهارت‌های کامپیوتر.
              آموزش پروژه‌محور با استانداردهای معتبر و تکیه بر نیاز واقعی بازار کار.
            </p>
            <div className="hero-actions">
              <button className="solid-btn large" onClick={() => navigate('courses')}>مشاهده دوره‌های آموزشی <ArrowLeft size={16}/></button>
              <button className="outline-btn large" onClick={() => navigate('consult')}>مشاوره و تعیین سطح</button>
            </div>
            <div className="trust-rail">
              <span><Award size={18}/> مدارک معتبر و قابل ترجمه</span>
              <span><Laptop size={18}/> کارگاه‌های کاملاً عملی</span>
              <span><ShieldCheck size={18}/> مدرسین فعال بازار کار</span>
              <span><Code2 size={18}/> پروژه‌محور و کاربردی</span>
            </div>
          </div>
          <div className="framework-visual" aria-hidden="true">
            <span className="frame-corner top-right"></span>
            <span className="frame-corner top-left"></span>
            <span className="frame-corner bottom-right"></span>
            <span className="frame-corner bottom-left"></span>
            <span className="framework-index">HEDAYATI · IT INSTITUTE</span>
            <span className="framework-word">هدایتی</span>
            <div className="framework-axis vertical"></div>
            <div className="framework-axis horizontal"></div>
            <div className="framework-step s1"><span>STEP 01</span><b>تعیین سطح و مشاوره</b><small>انتخاب مسیر هدفمند</small></div>
            <div className="framework-step s2"><span>STEP 02</span><b>کارگاه‌های تخصصی</b><small>یادگیری عمیق و عملی</small></div>
            <div className="framework-step s3"><span>STEP 03</span><b>انجام پروژه واقعی</b><small>آمادگی برای بازار کار</small></div>
            <div className="framework-step s4"><span>STEP 04</span><b>گواهینامه معتبر</b><small>مدرک قابل ترجمه</small></div>
            <div className="framework-stamp"><ShieldCheck size={28}/><span>CERTIFIED</span></div>
          </div>
        </div>
      </section>

      <section className="section path-section">
        <div className="container">
          <div className="section-heading row">
            <div>
              <span>دپارتمان‌های آموزشی</span>
              <h2>مسیرهای تخصصی برای هر سطح از مهارت</h2>
              <p>دسته‌بندی منظم دوره‌ها برای انتخاب سریع‌تر بر اساس هدف شغلی و علاقه شما.</p>
            </div>
            <button className="outline-btn" onClick={() => navigate('courses')}>مشاهده همه دسته‌ها <ArrowLeft size={16}/></button>
          </div>
          <CategoryStrip onSelect={id => navigate(`courses:${id}`)}/>
        </div>
      </section>

      <FeaturedCoursesGrid courses={courses} navigate={navigate}/>
      <RedesignedImpactSection navigate={navigate}/>
      <CtaBand navigate={navigate}/>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function AxisHome({ courses, navigate }) {
  return (
    <div className="axis-home" dir="rtl">
      <Header navigate={navigate}/>
      <section className="axis-hero">
        <div className="container axis-layout">
          <div className="axis-rail" aria-hidden="true">
            <span>START · LEARN · BUILD · CERTIFY</span>
            <i/>
            <small>EST. 2004</small>
          </div>
          <div className="axis-copy">
            <span className="micro-label">آموزش تخصصی کامپیوتر و IT</span>
            <h1>مسیر مستقیم از <b>یادگیری اصولی</b> تا <b>مهارت حرفه‌ای</b></h1>
            <p>
              در مجتمع آموزشی دکتر هدایتی، آموزش صرفاً انتقال تئوری نیست؛ بلکه یک مسیر گام‌به‌گام برای تسلط
              بر ابزارها، انجام سناریوهای واقعی و آماده‌سازی شما برای ورود به بازار کار است.
            </p>
            <div className="hero-actions">
              <button className="solid-btn large" onClick={() => navigate('courses')}>مشاهده تقویم دوره‌ها <ArrowLeft size={16}/></button>
              <button className="outline-btn large" onClick={() => navigate('consult')}>مشاوره رایگان</button>
            </div>
          </div>
          <div className="axis-board">
            <div className="axis-board-head"><span>ACTIVE TRACKS</span><Cpu size={18}/></div>
            <div className="route-list">
              <button onClick={() => navigate('courses:network')}><span>01</span><div><b>مسیر مهندسی شبکه و زیرساخت</b><small>Network+, CCNA, MCSA, Linux</small></div><ChevronLeft size={16}/></button>
              <button onClick={() => navigate('courses:programming')}><span>02</span><div><b>مسیر برنامه‌نویسی و وب</b><small>Python, JavaScript, React, Backend</small></div><ChevronLeft size={16}/></button>
              <button onClick={() => navigate('courses:security')}><span>03</span><div><b>مسیر امنیت سایبری</b><small>Network Security, CEH, SOC</small></div><ChevronLeft size={16}/></button>
              <button onClick={() => navigate('courses:data')}><span>04</span><div><b>مسیر علم داده و هوش مصنوعی</b><small>Python Data, Machine Learning, SQL</small></div><ChevronLeft size={16}/></button>
            </div>
            <div className="axis-board-foot"><Sparkles size={16}/><span>امکان ثبت‌نام آنلاین و مشاوره تخصصی</span><button onClick={() => navigate('consult')}>رزرو</button></div>
          </div>
        </div>
      </section>

      <section className="axis-proof-strip">
        <div className="container stats-grid">
          <div><strong>۲۰+</strong><span>سال سابقه آموزش تخصصی</span></div>
          <div><strong>۱۵K+</strong><span>دانش‌آموخته موفق</span></div>
          <div><strong>۷۰+</strong><span>عنوان دوره کاربردی</span></div>
          <div><strong>۱۰۰٪</strong><span>تمرکز بر پروژه‌های عملی</span></div>
        </div>
      </section>

      <FeaturedCoursesGrid courses={courses} navigate={navigate}/>

      <section className="section axis-categories">
        <div className="container">
          <div className="section-heading">
            <span>دسته‌بندی جامع</span>
            <h2>همه دپارتمان‌های آموزشی در یک نگاه</h2>
          </div>
          <CategoryStrip onSelect={id => navigate(`courses:${id}`)}/>
        </div>
      </section>

      <RedesignedImpactSection navigate={navigate}/>
      <CtaBand navigate={navigate}/>
      <Footer navigate={navigate}/>
    </div>
  );
}

export function NavigatorHome({ courses, navigate }) {
  return (
    <div className="navigator-home" dir="rtl">
      <Header navigate={navigate}/>
      <section className="navigator-hero">
        <div className="container navigator-grid">
          <div className="navigator-copy">
            <div className="nav-brandline"><Compass size={18}/><span>NAVIGATE YOUR TECH CAREER</span></div>
            <h1>انتخاب هوشمندانه دوره، <b>یادگیری عمیق</b> و ورود مطمئن به <b>بازار کار</b></h1>
            <p>
              مجتمع آموزشی دکتر هدایتی با ارائه دوره‌های تخصصی، کارگاه‌های مجهز و اساتید با تجربه کاری،
              شما را در ساختن رزومه‌ای قوی و مهارتی واقعی همراهی می‌کند.
            </p>
            <div className="navigator-actions">
              <button className="solid-btn large" onClick={() => navigate('courses')}>جستجوی همه دوره‌ها <ArrowLeft size={16}/></button>
              <button className="link-btn" onClick={() => navigate('certificates')}>استعلام آنلاین گواهینامه</button>
            </div>
          </div>
          <div className="navigator-console">
            <div className="console-top"><span>دپارتمان‌های اصلی مجتمع</span><Layers size={18}/></div>
            <div className="console-grid">
              <button onClick={() => navigate('courses:network')}><Terminal size={22}/><b>شبکه و زیرساخت</b><small>Network+, Cisco, MikroTik</small></button>
              <button onClick={() => navigate('courses:programming')}><Cpu size={22}/><b>برنامه‌نویسی و وب</b><small>Python, Frontend, Backend</small></button>
              <button onClick={() => navigate('courses:security')}><ShieldCheck size={22}/><b>امنیت اطلاعات</b><small>Network Defense, Pentest</small></button>
              <button onClick={() => navigate('courses:data')}><Sparkles size={22}/><b>دیتا و هوش مصنوعی</b><small>Machine Learning, BI</small></button>
            </div>
            <div className="console-meta">
              <span><CheckCircle2 size={15}/> گواهینامه‌های رسمی و قابل استعلام</span>
              <span><CheckCircle2 size={15}/> کلاس‌های حضوری و آنلاین با کیفیت یکسان</span>
            </div>
          </div>
        </div>
      </section>

      <section className="navigator-quick">
        <div className="container">
          <CategoryStrip onSelect={id => navigate(`courses:${id}`)}/>
        </div>
      </section>

      <FeaturedCoursesGrid courses={courses} navigate={navigate}/>
      <RedesignedImpactSection navigate={navigate}/>
      <CtaBand navigate={navigate}/>
      <Footer navigate={navigate}/>
    </div>
  );
}
