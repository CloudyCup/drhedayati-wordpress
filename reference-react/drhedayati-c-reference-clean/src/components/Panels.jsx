import React, { useState } from 'react';
import {
  Bell, BookOpen, Calendar, Check, CheckCircle2, ChevronLeft, Award,
  Edit3, ExternalLink, GraduationCap, LayoutDashboard,
  LogOut, Menu, PhoneCall, Plus, RotateCcw,
  Search, Settings, ShieldCheck, Sparkles, Star,
  Trash2, User, Users, X, HelpCircle
} from 'lucide-react';
import Logo from './Logo';
import { brand, categories, initialCourses, stats, studentData } from '../data/siteData';

export function AdminPanel({ courses, setCourses, navigate }) {
  const [activeTab, setActiveTab] = useState('dashboard');
  const [editingCourse, setEditingCourse] = useState(null);
  const [search, setSearch] = useState('');
  const [filterFeatured, setFilterFeatured] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [toast, setToast] = useState('');

  // Top action popover states
  const [notifOpen, setNotifOpen] = useState(false);
  const [quickActionOpen, setQuickActionOpen] = useState(false);

  // Manage requests
  const [consultRequests, setConsultRequests] = useState([
    { id: 1, name: 'سارا رضایی', phone: '09141234567', field: 'شبکه و زیرساخت (Cisco)', time: '۱۰ دقیقه پیش', status: 'جدید' },
    { id: 2, name: 'محمد احمدی', phone: '09129876543', field: 'پایتون و هوش مصنوعی', time: '۱ ساعت پیش', status: 'جدید' },
    { id: 3, name: 'امیرحسین تهرانی', phone: '09355551234', field: 'امنیت سایبری', time: 'دیروز', status: 'تماس گرفته شد' }
  ]);

  // Manage students
  const [studentList, setStudentList] = useState([
    { id: 'ST-101', name: 'علی حسینی', phone: '09141112233', course: 'Cisco CCNA', status: 'در حال تحصیل', progress: 65 },
    { id: 'ST-102', name: 'مریم اکبری', phone: '09124445566', course: 'برنامه‌نویسی Python', status: 'در حال تحصیل', progress: 80 },
    { id: 'ST-103', name: 'مهدی کریمی', phone: '09367778899', course: 'CompTIA Network+', status: 'فارغ‌التحصیل', progress: 100 },
    { id: 'ST-104', name: 'فاطمه موسوی', phone: '09193334455', course: 'طراحی و توسعه وب', status: 'در حال تحصیل', progress: 45 }
  ]);

  // Certificate generator mock
  const [issuedCerts, setIssuedCerts] = useState([
    { code: 'DH-1404-2318', student: 'دانشجوی نمونه', course: 'Python کاربردی', date: '۱۴۰۴/۱۱/۲۰' },
    { code: 'DH-1404-1902', student: 'مهدی کریمی', course: 'Network+', date: '۱۴۰۴/۱۰/۱۵' }
  ]);
  const [newCert, setNewCert] = useState({ student: '', course: '', code: '' });

  // Institute Settings Mock
  const [instSettings, setInstSettings] = useState({
    title: brand.name,
    registerPhone: brand.registerPhone,
    tabrizPhone: brand.phone,
    tehranPhone: brand.tehranPhone,
    tabrizAddress: brand.tabrizAddress,
    tehranAddress: brand.tehranAddress
  });

  const showToast = msg => {
    setToast(msg);
    setTimeout(() => setToast(''), 3000);
  };

  const toggleFeatured = (id, e) => {
    e?.stopPropagation();
    const target = courses.find(c => c.id === id);
    const willFeature = !target?.featured;
    const currentFeaturedCount = courses.filter(c => c.featured).length;
    if (willFeature && currentFeaturedCount >= 8) {
      showToast('حداکثر ۸ دوره می‌تواند در صفحه نخست ویژه باشد.');
      return;
    }
    setCourses(prev => prev.map(c => c.id === id ? { ...c, featured: !c.featured } : c));
    showToast(willFeature ? 'به بخش دوره‌های ویژه صفحه اصلی اضافه شد' : 'از دوره‌های ویژه حذف شد');
  };

  const togglePublish = (id, e) => {
    e?.stopPropagation();
    setCourses(prev => prev.map(c => c.id === id ? { ...c, published: !c.published } : c));
    showToast('وضعیت انتشار دوره تغییر کرد');
  };

  const deleteCourse = (id, e) => {
    e?.stopPropagation();
    if (window.confirm('آیا از حذف این دوره اطمینان دارید؟')) {
      setCourses(prev => prev.filter(c => c.id !== id));
      showToast('دوره حذف گردید');
    }
  };

  const saveCourse = c => {
    if (c.id) {
      setCourses(prev => prev.map(item => item.id === c.id ? c : item));
      showToast('تغییرات دوره با موفقیت ذخیره شد');
    } else {
      const newId = `c_${Date.now()}`;
      setCourses(prev => [{ ...c, id: newId, slug: c.slug || newId }, ...prev]);
      showToast('دوره جدید با موفقیت ایجاد شد');
    }
    setEditingCourse(null);
  };

  const resetAllCourses = () => {
    if (window.confirm('آیا مایلید تمام دوره‌ها به حالت اولیه بازگردند؟')) {
      setCourses(initialCourses);
      showToast('لیست دوره‌ها به تنظیمات کارخانه بازنشانی شد');
    }
  };

  const handleIssueCert = e => {
    e.preventDefault();
    if (!newCert.student || !newCert.course) return;
    const code = newCert.code || `DH-1405-${Math.floor(1000 + Math.random() * 9000)}`;
    setIssuedCerts(prev => [{ ...newCert, code, date: '۱۴۰۵/۰۱/۱۵' }, ...prev]);
    setNewCert({ student: '', course: '', code: '' });
    showToast(`گواهینامه ${code} صادر و ثبت شد`);
  };

  const markRequestDone = id => {
    setConsultRequests(prev => prev.map(r => r.id === id ? { ...r, status: 'تماس گرفته شد' } : r));
    showToast('وضعیت درخواست به‌روزرسانی شد');
  };

  const filteredCourses = courses.filter(c => {
    const matchesSearch = c.title.includes(search) || c.english.toLowerCase().includes(search.toLowerCase());
    const matchesFeatured = filterFeatured ? c.featured : true;
    return matchesSearch && matchesFeatured;
  });

  const featuredCount = courses.filter(c => c.featured).length;

  return (
    <div className="panel-shell" dir="rtl">
      {/* Toast Notification */}
      {toast && (
        <div className="panel-toast"><CheckCircle2 size={16}/><span>{toast}</span></div>
      )}

      {/* Sidebar */}
      <aside className={`panel-sidebar ${sidebarOpen ? 'open' : ''}`}>
        <div className="panel-logo">
          <Logo />
          <button className="sidebar-close" onClick={() => setSidebarOpen(false)}><X size={20}/></button>
        </div>
        <div className="role-chip">پنل مدیریت مجتمع دکتر هدایتی</div>
        <nav>
          <button className={activeTab === 'dashboard' ? 'active' : ''} onClick={() => { setActiveTab('dashboard'); setSidebarOpen(false); }}>
            <LayoutDashboard size={18}/><span>داشبورد جامع</span>
          </button>
          <button className={activeTab === 'courses' ? 'active' : ''} onClick={() => { setActiveTab('courses'); setSidebarOpen(false); }}>
            <BookOpen size={18}/><span>مدیریت دوره‌ها</span><b>{courses.length}</b>
          </button>
          <button className={activeTab === 'featured' ? 'active' : ''} onClick={() => { setActiveTab('featured'); setSidebarOpen(false); }}>
            <Sparkles size={18}/><span>دوره‌های ویژه صفحه نخست</span><b>{featuredCount}</b>
          </button>
          <button className={activeTab === 'requests' ? 'active' : ''} onClick={() => { setActiveTab('requests'); setSidebarOpen(false); }}>
            <PhoneCall size={18}/><span>درخواست‌های مشاوره</span><b>{consultRequests.filter(r => r.status === 'جدید').length}</b>
          </button>
          <button className={activeTab === 'students' ? 'active' : ''} onClick={() => { setActiveTab('students'); setSidebarOpen(false); }}>
            <Users size={18}/><span>دانشجویان و ثبت‌نام‌ها</span>
          </button>
          <button className={activeTab === 'certificates' ? 'active' : ''} onClick={() => { setActiveTab('certificates'); setSidebarOpen(false); }}>
            <ShieldCheck size={18}/><span>صدور و استعلام مدرک</span>
          </button>
          <button className={activeTab === 'settings' ? 'active' : ''} onClick={() => { setActiveTab('settings'); setSidebarOpen(false); }}>
            <Settings size={18}/><span>تنظیمات مجتمع</span>
          </button>
        </nav>
        <div className="panel-bottom">
          <button onClick={() => navigate('home')}><ExternalLink size={18}/><span>مشاهده وب‌سایت</span></button>
          <button onClick={() => navigate('home')}><LogOut size={18}/><span>خروج از پنل</span></button>
        </div>
      </aside>

      {/* Main Area */}
      <main className="panel-main">
        <header className="panel-topbar">
          <button className="panel-menu" onClick={() => setSidebarOpen(true)}><Menu size={20}/></button>
          <div><small>مدیر سیستم</small><b>پنل مدیریت یکپارچه</b></div>
          <div className="panel-top-actions">
            <button className={`has-dot ${notifOpen ? 'active' : ''}`} title="اعلان‌ها" onClick={() => { setNotifOpen(!notifOpen); setQuickActionOpen(false); }}>
              <Bell size={18}/>
            </button>
            <button className={quickActionOpen ? 'active' : ''} title="عملیات سریع" onClick={() => { setQuickActionOpen(!quickActionOpen); setNotifOpen(false); }}>
              <Sparkles size={18}/>
            </button>
            <div className="avatar">مدیر</div>
          </div>

          {/* Notifications Popover */}
          {notifOpen && (
            <div className="panel-popover">
              <b>اعلان‌های سیستم</b>
              <span>{consultRequests.filter(r => r.status === 'جدید').length} درخواست مشاوره جدید در انتظار بررسی است.</span>
              <button onClick={() => { setActiveTab('requests'); setNotifOpen(false); }}>مشاهده همه درخواست‌ها <ChevronLeft size={14}/></button>
            </div>
          )}

          {/* Quick Action Popover */}
          {quickActionOpen && (
            <div className="panel-popover">
              <b>دسترسی سریع</b>
              <button onClick={() => { setEditingCourse({ title: '', english: '', category: 'network', level: 'از پایه', duration: '۴۰ ساعت', summary: '', tags: [], featured: false, published: true }); setQuickActionOpen(false); }}>
                <Plus size={14}/> افزودن دوره جدید
              </button>
              <button onClick={() => { setActiveTab('certificates'); setQuickActionOpen(false); }}>
                <ShieldCheck size={14}/> صدور فوری گواهینامه
              </button>
              <button onClick={() => { setActiveTab('requests'); setQuickActionOpen(false); }}>
                <PhoneCall size={14}/> تماس‌های در انتظار
              </button>
            </div>
          )}
        </header>

        <div className="panel-content">
          {/* TAB 1: DASHBOARD */}
          {activeTab === 'dashboard' && (
            <div>
              <div className="panel-heading">
                <div><span>گزارش عملکرد کلی</span><h1>داشبورد مدیریت</h1></div>
                <button className="solid-btn" onClick={() => setEditingCourse({ title: '', english: '', category: 'network', level: 'از پایه', duration: '۴۰ ساعت', summary: '', tags: [], featured: false, published: true })}>
                  <Plus size={16}/><span>تعریف دوره جدید</span>
                </button>
              </div>

              <div className="panel-kpis interactive-kpis">
                <button onClick={() => setActiveTab('courses')}>
                  <span>دوره‌های فعال</span>
                  <b>{courses.length}</b>
                  <small>مدیریت دوره‌ها <ChevronLeft size={12}/></small>
                </button>
                <button onClick={() => setActiveTab('featured')}>
                  <span>دوره‌های ویژه صفحه ۱</span>
                  <b>{featuredCount}</b>
                  <small>حداکثر ۸ مورد <ChevronLeft size={12}/></small>
                </button>
                <button onClick={() => setActiveTab('requests')}>
                  <span>درخواست‌های جدید</span>
                  <b>{consultRequests.filter(r => r.status === 'جدید').length}</b>
                  <small>نیاز به پیگیری <ChevronLeft size={12}/></small>
                </button>
                <button onClick={() => setActiveTab('students')}>
                  <span>دانشجویان فعال</span>
                  <b>{studentList.length}</b>
                  <small>مشاهده لیست <ChevronLeft size={12}/></small>
                </button>
              </div>

              <div className="admin-dashboard-grid">
                <div className="dashboard-card">
                  <h2>عملیات سریع مدیریتی</h2>
                  <div className="quick-admin-actions">
                    <button onClick={() => setActiveTab('courses')}>
                      <BookOpen size={20}/>
                      <b>مدیریت و ویرایش سرفصل‌ها</b>
                      <small>ویرایش نام، ساعات و وضعیت نمایش</small>
                    </button>
                    <button onClick={() => setActiveTab('featured')}>
                      <Sparkles size={20}/>
                      <b>انتخاب دوره‌های صفحه اصلی</b>
                      <small>تنظیم دو ردیف چهارتایی ویژه</small>
                    </button>
                    <button onClick={() => setActiveTab('requests')}>
                      <PhoneCall size={20}/>
                      <b>پیگیری فرم‌های مشاوره</b>
                      <small>ثبت وضعیت تماس با دانشجو</small>
                    </button>
                    <button onClick={() => setActiveTab('certificates')}>
                      <ShieldCheck size={20}/>
                      <b>سامانه گواهینامه‌ها</b>
                      <small>صدور مدارک با بارکد و شناسه</small>
                    </button>
                  </div>
                </div>

                <div className="dashboard-card featured-admin-card">
                  <span>وضعیت صفحه اصلی</span>
                  <h2>{featuredCount} / 8 دوره</h2>
                  <p>تعداد دوره‌های ویژه تعیین شده برای نمایش در شبکه ۲ ردیف چهارتایی صفحه اول.</p>
                  <button className="outline-btn" onClick={() => setActiveTab('featured')}>ویرایش چینش ویژه <ChevronLeft size={14}/></button>
                </div>
              </div>
            </div>
          )}

          {/* TAB 2: COURSES MANAGEMENT */}
          {activeTab === 'courses' && (
            <div>
              <div className="panel-heading">
                <div><span>مدیریت محتوا</span><h1>فهرست دوره‌های آموزشی</h1></div>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <button className="outline-btn" onClick={resetAllCourses} title="بازنشانی به حالت پیش‌فرض"><RotateCcw size={15}/><span>بازنشانی</span></button>
                  <button className="solid-btn" onClick={() => setEditingCourse({ title: '', english: '', category: 'network', level: 'از پایه', duration: '۴۰ ساعت', summary: '', tags: [], featured: false, published: true })}>
                    <Plus size={16}/><span>دوره جدید</span>
                  </button>
                </div>
              </div>

              <div className="admin-table-card">
                <div className="table-toolbar">
                  <label><Search size={16}/><input placeholder="جستجو بر اساس عنوان دوره یا انگلیسی..." value={search} onChange={e => setSearch(e.target.value)}/></label>
                  <button className={filterFeatured ? 'active-filter' : ''} onClick={() => setFilterFeatured(!filterFeatured)}>
                    {filterFeatured ? 'نمایش همه' : 'فقط ویژه‌ها'}
                  </button>
                </div>
                <div className="admin-table">
                  <div className="tr th">
                    <span>عنوان دوره</span>
                    <span>دپارتمان</span>
                    <span>مدت زمان</span>
                    <span>ویژه صفحه ۱</span>
                    <span>عملیات</span>
                  </div>
                  {filteredCourses.map(c => (
                    <div key={c.id} className="tr">
                      <div className="course-cell">
                        <i>{c.english.slice(0, 3)}</i>
                        <span><b>{c.title}</b><small>{c.english}</small></span>
                      </div>
                      <span>{categories.find(cat => cat.id === c.category)?.title || c.category}</span>
                      <span>{c.duration}</span>
                      <span>
                        <button className={`feature-star ${c.featured ? 'on' : ''}`} onClick={e => toggleFeatured(c.id, e)} title={c.featured ? 'حذف از ویژه' : 'افزودن به ویژه'}>
                          <Star size={16} fill={c.featured ? '#ffb703' : 'none'}/>
                        </button>
                      </span>
                      <div className="row-actions">
                        <button onClick={() => setEditingCourse(c)} title="ویرایش"><Edit3 size={15}/></button>
                        <button onClick={e => togglePublish(c.id, e)} title={c.published ? 'عدم انتشار' : 'انتشار'} style={{ color: c.published ? '#16a34a' : '#9ca3af' }}>
                          <Check size={15}/>
                        </button>
                        <button onClick={e => deleteCourse(c.id, e)} title="حذف"><Trash2 size={15}/></button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* TAB 3: FEATURED CURATION */}
          {activeTab === 'featured' && (
            <div>
              <div className="panel-heading">
                <div><span>مدیریت صفحه اصلی</span><h1>دوره‌های ویژه صفحه نخست ({featuredCount} از ۸)</h1></div>
                <span className="selection-count">برای بهترین نمایش در قالب ۲ ردیف چهارتایی، دقیقاً ۸ دوره را علامت بزنید.</span>
              </div>

              <div className="dashboard-card">
                <h2>انتخاب سریع دوره‌های ویژه</h2>
                <div className="feature-picker">
                  {courses.map(c => (
                    <button key={c.id} className={c.featured ? 'selected' : ''} onClick={() => toggleFeatured(c.id)}>
                      <Star size={18} fill={c.featured ? '#ffb703' : 'none'}/>
                      <div>
                        <b>{c.title}</b>
                        <small>{c.english} · {c.duration}</small>
                      </div>
                    </button>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* TAB 4: REQUESTS */}
          {activeTab === 'requests' && (
            <div>
              <div className="panel-heading">
                <div><span>پذیرش و مشاوره</span><h1>درخواست‌های مشاوره و تعیین سطح</h1></div>
              </div>

              <div className="request-grid">
                {consultRequests.map(r => (
                  <div key={r.id} className="request-card">
                    <div className="request-head">
                      <b>{r.name}</b>
                      <span className="request-state">{r.status}</span>
                    </div>
                    <p>{r.field}</p>
                    <a href={`tel:${r.phone}`}>{r.phone}</a>
                    <div>
                      {r.status === 'جدید' && (
                        <button className="solid-btn" onClick={() => markRequestDone(r.id)}>ثبت تماس گرفته شد</button>
                      )}
                      <a className="outline-btn" href={`tel:${r.phone}`}>تماس مستقیم</a>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* TAB 5: STUDENTS */}
          {activeTab === 'students' && (
            <div>
              <div className="panel-heading">
                <div><span>آموزش و دانشجویان</span><h1>لیست دانشجویان و وضعیت دوره‌ها</h1></div>
              </div>
              <div className="dashboard-card">
                <div className="simple-list">
                  {studentList.map(st => (
                    <div key={st.id}>
                      <div className="avatar mini">{st.name.slice(0, 1)}</div>
                      <div><b>{st.name}</b><small>{st.course} · {st.id}</small></div>
                      <span className="list-status">{st.status}</span>
                      <strong>{st.progress}٪</strong>
                      <button onClick={() => showToast(`پرونده دانشجو ${st.name} باز شد`)}>مشاهده سوابق</button>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* TAB 6: CERTIFICATES */}
          {activeTab === 'certificates' && (
            <div>
              <div className="panel-heading">
                <div><span>مدارک رسمی</span><h1>صدور و مدیریت گواهینامه‌ها</h1></div>
              </div>

              <div className="cert-admin-layout">
                <div className="dashboard-card">
                  <h2>صدور گواهینامه جدید</h2>
                  <form onSubmit={handleIssueCert}>
                    <label className="panel-input">
                      <span>نام و نام خانوادگی دانشجو</span>
                      <input required value={newCert.student} onChange={e => setNewCert({ ...newCert, student: e.target.value })} placeholder="مثال: رضا احمدی"/>
                    </label>
                    <label className="panel-input">
                      <span>عنوان دوره</span>
                      <input required value={newCert.course} onChange={e => setNewCert({ ...newCert, course: e.target.value })} placeholder="مثال: Cisco CCNA"/>
                    </label>
                    <label className="panel-input">
                      <span>کد اختصاصی (اختیاری)</span>
                      <input value={newCert.code} onChange={e => setNewCert({ ...newCert, code: e.target.value })} placeholder="خودکار در صورت خالی بودن"/>
                    </label>
                    <button type="submit" className="solid-btn" style={{ marginTop: '10px' }}><ShieldCheck size={16}/> صدور مدرک</button>
                  </form>
                </div>

                <div className="dashboard-card">
                  <h2>گواهینامه‌های صادر شده اخیر</h2>
                  <div className="simple-list">
                    {issuedCerts.map((cert, idx) => (
                      <div key={idx}>
                        <Award size={20}/>
                        <div><b>{cert.student}</b><small>{cert.course}</small></div>
                        <span className="list-status">{cert.date}</span>
                        <strong>{cert.code}</strong>
                        <button onClick={() => showToast(`کد رهگیری ${cert.code} کپی شد`)}>کپی کد</button>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 7: INSTITUTE SETTINGS */}
          {activeTab === 'settings' && (
            <div>
              <div className="panel-heading">
                <div><span>پیکربندی سامانه</span><h1>تنظیمات اطلاعات مجتمع</h1></div>
              </div>
              <div className="dashboard-card settings-grid">
                <div>
                  <h2>مشخصات و تلفن‌های تماس</h2>
                  <label className="panel-input">
                    <span>نام رسمی مجتمع</span>
                    <input value={instSettings.title} onChange={e => setInstSettings({ ...instSettings, title: e.target.value })}/>
                  </label>
                  <label className="panel-input">
                    <span>شماره ثبت‌نام و مشاوره</span>
                    <input value={instSettings.registerPhone} onChange={e => setInstSettings({ ...instSettings, registerPhone: e.target.value })}/>
                  </label>
                  <label className="panel-input">
                    <span>تلفن تبریز</span>
                    <input value={instSettings.tabrizPhone} onChange={e => setInstSettings({ ...instSettings, tabrizPhone: e.target.value })}/>
                  </label>
                  <label className="panel-input">
                    <span>تلفن تهران</span>
                    <input value={instSettings.tehranPhone} onChange={e => setInstSettings({ ...instSettings, tehranPhone: e.target.value })}/>
                  </label>
                  <button className="solid-btn" onClick={() => showToast('تنظیمات با موفقیت ذخیره شد')}>ذخیره تغییرات</button>
                </div>
                <div>
                  <h2>آدرس شعب</h2>
                  <label className="panel-input">
                    <span>آدرس شعبه تبریز</span>
                    <textarea rows={3} value={instSettings.tabrizAddress} onChange={e => setInstSettings({ ...instSettings, tabrizAddress: e.target.value })}/>
                  </label>
                  <label className="panel-input">
                    <span>آدرس دفتر تهران</span>
                    <textarea rows={3} value={instSettings.tehranAddress} onChange={e => setInstSettings({ ...instSettings, tehranAddress: e.target.value })}/>
                  </label>
                </div>
              </div>
            </div>
          )}
        </div>
      </main>

      {/* Edit Course Drawer */}
      {editingCourse && (
        <div className="drawer-backdrop" onClick={() => setEditingCourse(null)}>
          <div className="edit-drawer" onClick={e => e.stopPropagation()}>
            <header>
              <div><small>ویرایش اطلاعات</small><h2>{editingCourse.id ? 'ویرایش دوره' : 'تعریف دوره جدید'}</h2></div>
              <button onClick={() => setEditingCourse(null)}><X size={20}/></button>
            </header>
            <div className="drawer-form">
              <label><span>عنوان فارسی دوره</span><input value={editingCourse.title} onChange={e => setEditingCourse({ ...editingCourse, title: e.target.value })}/></label>
              <label><span>عنوان انگلیسی / کدتخصصی</span><input value={editingCourse.english} onChange={e => setEditingCourse({ ...editingCourse, english: e.target.value })}/></label>
              <label><span>دپارتمان تخصصی</span>
                <select value={editingCourse.category} onChange={e => setEditingCourse({ ...editingCourse, category: e.target.value })}>
                  {categories.map(c => <option key={c.id} value={c.id}>{c.title}</option>)}
                </select>
              </label>
              <div className="two-cols">
                <label><span>مدت دوره (ساعت)</span><input value={editingCourse.duration} onChange={e => setEditingCourse({ ...editingCourse, duration: e.target.value })}/></label>
                <label><span>سطح برگزاری</span><input value={editingCourse.level} onChange={e => setEditingCourse({ ...editingCourse, level: e.target.value })}/></label>
              </div>
              <label><span>وضعیت ظرفیت یا شروع</span><input value={editingCourse.seats || ''} placeholder="مثال: ثبت‌نام باز" onChange={e => setEditingCourse({ ...editingCourse, seats: e.target.value })}/></label>
              <label><span>خلاصه و شرح کوتاه دوره</span><textarea rows={3} value={editingCourse.summary} onChange={e => setEditingCourse({ ...editingCourse, summary: e.target.value })}/></label>
              <label><span>برچسب‌ها (با کاما جدا کنید)</span>
                <input value={(editingCourse.tags || []).join(', ')} onChange={e => setEditingCourse({ ...editingCourse, tags: e.target.value.split(',').map(t => t.trim()).filter(Boolean) })}/>
              </label>
              <div className="toggle-row">
                <span>نمایش در دوره‌های ویژه صفحه ۱</span>
                <button className={editingCourse.featured ? 'on' : ''} onClick={() => setEditingCourse({ ...editingCourse, featured: !editingCourse.featured })}><i/></button>
              </div>
            </div>
            <footer>
              <button className="outline-btn" onClick={() => setEditingCourse(null)}>انصراف</button>
              <button className="solid-btn" onClick={() => saveCourse(editingCourse)}>ذخیره دوره</button>
            </footer>
          </div>
        </div>
      )}
    </div>
  );
}

export function StudentPanel({ navigate }) {
  const [activeTab, setActiveTab] = useState('overview');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [toast, setToast] = useState('');

  const showToast = msg => {
    setToast(msg);
    setTimeout(() => setToast(''), 3000);
  };

  return (
    <div className="panel-shell" dir="rtl">
      {toast && (
        <div className="panel-toast"><CheckCircle2 size={16}/><span>{toast}</span></div>
      )}

      <aside className={`panel-sidebar ${sidebarOpen ? 'open' : ''}`}>
        <div className="panel-logo">
          <Logo />
          <button className="sidebar-close" onClick={() => setSidebarOpen(false)}><X size={20}/></button>
        </div>
        <div className="role-chip">پنل دانشجویی مجتمع دکتر هدایتی</div>
        <nav>
          <button className={activeTab === 'overview' ? 'active' : ''} onClick={() => { setActiveTab('overview'); setSidebarOpen(false); }}>
            <LayoutDashboard size={18}/><span>داشبورد دانشجو</span>
          </button>
          <button className={activeTab === 'mycourses' ? 'active' : ''} onClick={() => { setActiveTab('mycourses'); setSidebarOpen(false); }}>
            <BookOpen size={18}/><span>دوره‌های من</span><b>۲</b>
          </button>
          <button className={activeTab === 'calendar' ? 'active' : ''} onClick={() => { setActiveTab('calendar'); setSidebarOpen(false); }}>
            <Calendar size={18}/><span>برنامه کلاس‌ها</span>
          </button>
          <button className={activeTab === 'certificates' ? 'active' : ''} onClick={() => { setActiveTab('certificates'); setSidebarOpen(false); }}>
            <ShieldCheck size={18}/><span>گواهینامه‌های من</span>
          </button>
          <button className={activeTab === 'support' ? 'active' : ''} onClick={() => { setActiveTab('support'); setSidebarOpen(false); }}>
            <HelpCircle size={18}/><span>پشتیبانی و تیکت</span>
          </button>
          <button className={activeTab === 'profile' ? 'active' : ''} onClick={() => { setActiveTab('profile'); setSidebarOpen(false); }}>
            <User size={18}/><span>پروفایل کاربری</span>
          </button>
        </nav>
        <div className="panel-bottom">
          <button onClick={() => navigate('home')}><ExternalLink size={18}/><span>مشاهده وب‌سایت</span></button>
          <button onClick={() => navigate('home')}><LogOut size={18}/><span>خروج</span></button>
        </div>
      </aside>

      <main className="panel-main">
        <header className="panel-topbar">
          <button className="panel-menu" onClick={() => setSidebarOpen(true)}><Menu size={20}/></button>
          <div><small>خوش آمدید</small><b>{studentData.name}</b></div>
          <div className="panel-top-actions">
            <button title="اعلان‌ها" onClick={() => showToast('اعلان جدیدی وجود ندارد')}><Bell size={18}/></button>
            <div className="avatar">دانشجو</div>
          </div>
        </header>

        <div className="panel-content student-content">
          {/* TAB 1: OVERVIEW */}
          {activeTab === 'overview' && (
            <div>
              <div className="panel-heading">
                <div><span>میز کار آموزشی</span><h1>داشبورد یادگیری</h1></div>
                <button className="outline-btn" onClick={() => navigate('courses')}>مشاهده کاتالوگ دوره‌ها</button>
              </div>

              <div className="student-grid">
                <div className="student-courses">
                  <div className="card-heading">
                    <div><span>درحال یادگیری</span><h2>دوره‌های فعال شما</h2></div>
                    <button onClick={() => setActiveTab('mycourses')}>مشاهده همه <ChevronLeft size={16}/></button>
                  </div>
                  {studentData.activeCourses.map((c, i) => (
                    <div key={i} className="progress-course">
                      <div className="progress-icon">{c.title.slice(0, 2)}</div>
                      <div className="progress-info">
                        <div><h3>{c.title}</h3><span>{c.progress}٪ پیشرفت</span></div>
                        <div className="progress-bar"><i style={{ width: `${c.progress}%` }}></i></div>
                        <small>{c.next}</small>
                      </div>
                      <div className="next-date"><small>جلسه بعدی</small><b>{c.date}</b></div>
                      <button className="circle-arrow" onClick={() => showToast(`ورود به کلاس آنلاین ${c.title}`)}><ChevronLeft size={16}/></button>
                    </div>
                  ))}
                </div>

                <div className="next-class">
                  <span>جلسه بعدی شما</span>
                  <h2>برنامه‌نویسی Python</h2>
                  <p>مبحث: توابع بازگشتی و کار با فایل‌ها در پایتون</p>
                  <div className="big-time">۱۸:۳۰</div>
                  <p>فردا — کارگاه شماره ۳ (حضوری)</p>
                  <button className="solid-btn" style={{ marginTop: 'auto' }} onClick={() => showToast('یادآور کلاس برای شما فعال شد')}>تنظیم یادآور کلاس</button>
                </div>
              </div>

              <div className="student-bottom-grid">
                <button className="as-button" onClick={() => setActiveTab('certificates')}>
                  <div className="certificate-card">
                    <ShieldCheck size={28}/>
                    <div>
                      <span>گواهینامه‌های رسمی</span>
                      <h3>استعلام مدرک ICDL</h3>
                      <p>کد رهگیری: DH-1404-2318</p>
                    </div>
                    <span className="verified"><Check size={14}/> تایید شده</span>
                  </div>
                </button>

                <button className="as-button" onClick={() => setActiveTab('support')}>
                  <div className="support-card">
                    <PhoneCall size={28}/>
                    <div>
                      <span>پشتیبانی مجتمع</span>
                      <h3>ارتباط مستقیم با واحد آموزش</h3>
                      <p>پاسخگویی در ساعات کاری</p>
                    </div>
                    <span className="solid-btn" style={{ fontSize: '11px', padding: '6px 12px' }}>ارسال پیام</span>
                  </div>
                </button>
              </div>
            </div>
          )}

          {/* TAB 2: MY COURSES */}
          {activeTab === 'mycourses' && (
            <div>
              <div className="panel-heading">
                <div><span>دوره‌های من</span><h1>فهرست کلاس‌ها و کارگاه‌ها</h1></div>
              </div>
              <div className="my-course-grid">
                <article className="dashboard-card">
                  <div className="course-panel-icon">PY</div>
                  <h2>برنامه‌نویسی Python جامع</h2>
                  <p>مدرس: تیم برنامه‌نویسی مجتمع · کلاس سه‌شنبه‌ها و پنجشنبه‌ها</p>
                  <div className="progress-bar"><i style={{ width: '68%' }}></i></div>
                  <strong>۶۸٪ تکمیل شده (جلسه ۱۲ از ۱۸)</strong>
                  <button className="solid-btn" onClick={() => showToast('دانلود جزوه جلسه ۱۲ آغاز شد')}>دانلود جزوه و سورس‌کدها</button>
                </article>

                <article className="dashboard-card">
                  <div className="course-panel-icon">N+</div>
                  <h2>CompTIA Network+</h2>
                  <p>مدرس: تیم شبکه مجتمع · کلاس دوشنبه‌ها ساعت ۱۷:۰۰</p>
                  <div className="progress-bar"><i style={{ width: '34%' }}></i></div>
                  <strong>۳۴٪ تکمیل شده (جلسه ۶ از ۱۶)</strong>
                  <button className="solid-btn" onClick={() => showToast('دانلود آزمایشگاه Subnetting')}>دانلود تمرین‌های کارگاهی</button>
                </article>
              </div>
            </div>
          )}

          {/* TAB 3: CALENDAR */}
          {activeTab === 'calendar' && (
            <div>
              <div className="panel-heading">
                <div><span>برنامه هفتگی</span><h1>تقویم جلسات و کارگاه‌ها</h1></div>
              </div>
              <div className="calendar-list">
                <article>
                  <div><b>پنجشنبه ۲۳ مرداد</b><span>ساعت ۱۸:۳۰ تا ۲۰:۳۰</span></div>
                  <strong>Python</strong>
                  <p>جلسه ۱۲: مدیریت خطاها و ماژول‌های استاندارد</p>
                  <span className="list-status">کارگاه ۳</span>
                </article>
                <article>
                  <div><b>شنبه ۲۵ مرداد</b><span>ساعت ۱۷:۰۰ تا ۱۹:۰۰</span></div>
                  <strong>Network+</strong>
                  <p>جلسه ۷: مسیریابی و پیکربندی VLAN</p>
                  <span className="list-status">لابراتوار شبکه</span>
                </article>
                <article>
                  <div><b>سه‌شنبه ۲۸ مرداد</b><span>ساعت ۱۸:۳۰ تا ۲۰:۳۰</span></div>
                  <strong>Python</strong>
                  <p>جلسه ۱۳: کار با فایل‌های JSON و CSV</p>
                  <span className="list-status">کارگاه ۳</span>
                </article>
              </div>
            </div>
          )}

          {/* TAB 4: CERTIFICATES */}
          {activeTab === 'certificates' && (
            <div>
              <div className="panel-heading">
                <div><span>مدارک من</span><h1>گواهینامه‌های پایان دوره</h1></div>
              </div>
              <div className="certificate-detail-panel">
                <ShieldCheck size={42}/>
                <div>
                  <span className="eyebrow" style={{ marginBottom: '6px' }}>گواهینامه بین‌المللی</span>
                  <h2>مهارت‌های کامپیوتر ICDL جامع</h2>
                  <p>صادره در تاریخ: ۱۴۰۴/۱۱/۲۰ · شماره گواهینامه: DH-1404-2318</p>
                </div>
                <span className="verified"><Check size={14}/> تایید شده و قابل استعلام</span>
                <button className="solid-btn" onClick={() => showToast('فایل گواهینامه با کیفیت چاپ دانلود شد')}>دانلود نسخه دیجیتال</button>
              </div>
            </div>
          )}

          {/* TAB 5: SUPPORT */}
          {activeTab === 'support' && (
            <div>
              <div className="panel-heading">
                <div><span>پشتیبانی</span><h1>ارسال تیکت به واحد آموزش</h1></div>
              </div>
              <div className="dashboard-card support-layout">
                <div>
                  <h2>ثبت درخواست جدید</h2>
                  <label className="panel-input">
                    <span>موضوع درخواست</span>
                    <input placeholder="مثال: غیبت در جلسه و دریافت فایل ضبط شده"/>
                  </label>
                  <label className="panel-input">
                    <span>مربوط به دوره</span>
                    <select>
                      <option>برنامه‌نویسی Python</option>
                      <option>CompTIA Network+</option>
                      <option>امور مالی و ثبت‌نام</option>
                    </select>
                  </label>
                  <label className="panel-input">
                    <span>متن پیام</span>
                    <textarea rows={4} placeholder="توضیحات کامل درخواست..."/>
                  </label>
                  <button className="solid-btn" onClick={() => showToast('تیکت شما با شماره پیگیری #4892 ثبت شد')}>ارسال تیکت</button>
                </div>
                <div>
                  <h2>تلفن‌های پشتیبانی مستقیم</h2>
                  <p>در ساعات اداری می‌توانید با کارشناسان آموزش تماس بگیرید:</p>
                  <div className="simple-list" style={{ marginTop: '14px' }}>
                    <div>
                      <PhoneCall size={18}/>
                      <div><b>واحد آموزش و کلاس‌ها</b><small>{brand.phone}</small></div>
                    </div>
                    <div>
                      <PhoneCall size={18}/>
                      <div><b>واحد ثبت‌نام و مشاوره</b><small>{brand.registerPhone}</small></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 6: PROFILE */}
          {activeTab === 'profile' && (
            <div>
              <div className="panel-heading">
                <div><span>اطلاعات فردی</span><h1>پروفایل کاربری دانشجو</h1></div>
              </div>
              <div className="dashboard-card profile-form">
                <div className="profile-avatar"><User size={36}/></div>
                <label className="panel-input"><span>نام و نام خانوادگی</span><input defaultValue={studentData.name}/></label>
                <label className="panel-input"><span>شماره تماس</span><input defaultValue="09141112233"/></label>
                <label className="panel-input"><span>کد ملی</span><input defaultValue="1360000000"/></label>
                <label className="panel-input"><span>ایمیل</span><input defaultValue="student@example.com"/></label>
                <button className="solid-btn" onClick={() => showToast('اطلاعات پروفایل به‌روزرسانی شد')}>ذخیره تغییرات</button>
              </div>
            </div>
          )}
        </div>
      </main>
    </div>
  );
}
