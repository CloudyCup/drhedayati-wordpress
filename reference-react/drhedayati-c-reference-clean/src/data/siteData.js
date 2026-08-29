export const brand = {
  name: 'مجتمع آموزشی دکتر هدایتی',
  shortName: 'دکتر هدایتی',
  tagline: 'مهارت واقعی، مسیر واقعی',
  phone: '04133377601',
  registerPhone: '04133373735',
  generalPhone: '04133369099',
  tehranPhone: '02188793566',
  tabrizAddress: 'تبریز، چهارراه آبرسان، بعد از پارکینگ هتل گسترش، ساختمان ۷',
  tehranAddress: 'تهران، خیابان ولیعصر، بالاتر از پارک ساعی، کوچه همسایگان، پلاک ۷'
};

export const categories = [
  { id: 'network', title: 'شبکه و IT', english: 'NETWORK & IT', count: 18, icon: '⌘' },
  { id: 'security', title: 'امنیت شبکه', english: 'CYBER SECURITY', count: 8, icon: '◈' },
  { id: 'programming', title: 'برنامه‌نویسی و وب', english: 'CODE & WEB', count: 12, icon: '</>' },
  { id: 'data', title: 'دیتا و هوش مصنوعی', english: 'DATA & AI', count: 7, icon: '∑' },
  { id: 'design', title: 'گرافیک و تولید محتوا', english: 'DESIGN', count: 10, icon: '✦' },
  { id: 'foundation', title: 'ICDL و مهارت‌های پایه', english: 'FOUNDATION', count: 9, icon: '⌁' },
  { id: 'finance', title: 'حسابداری و بازار مالی', english: 'FINANCE', count: 9, icon: '%' },
  { id: 'kids', title: 'کامپیوتر برای کودکان', english: 'KIDS', count: 6, icon: '◎' }
];

export const initialCourses = [
  {
    id: 'python', slug: 'python', title: 'برنامه‌نویسی Python', english: 'PYTHON', category: 'programming',
    level: 'از پایه تا کاربردی', duration: '۴۸ ساعت', schedule: 'شروع دوره جدید: به‌زودی', teacher: 'تیم برنامه‌نویسی مجتمع',
    summary: 'یادگیری اصول برنامه‌نویسی، حل مسئله و ساخت پروژه‌های واقعی با پایتون.',
    tags: ['Python', 'Automation', 'Project Based'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'ccna', slug: 'ccna', title: 'Cisco CCNA', english: 'CCNA', category: 'network',
    level: 'تخصصی', duration: '۶۰ ساعت', schedule: 'کلاس حضوری', teacher: 'تیم شبکه مجتمع',
    summary: 'مسیر عملی طراحی، راه‌اندازی و مدیریت شبکه با تمرکز بر مفاهیم و تجهیزات Cisco.',
    tags: ['Cisco', 'Routing', 'Switching'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'data-science', slug: 'data-science', title: 'Data Science & Machine Learning', english: 'DATA SCIENCE', category: 'data',
    level: 'متوسط تا پیشرفته', duration: '۷۲ ساعت', schedule: 'دوره پروژه‌محور', teacher: 'تیم Data Science',
    summary: 'از تحلیل داده تا مدل‌های یادگیری ماشین، با تمرکز بر درک عمیق و پروژه‌های کاربردی.',
    tags: ['Data', 'ML', 'Python'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ظرفیت محدود'
  },
  {
    id: 'network-plus', slug: 'network-plus', title: 'CompTIA Network+', english: 'NETWORK+', category: 'network',
    level: 'پایه تا متوسط', duration: '۴۸ ساعت', schedule: 'ثبت‌نام فعال', teacher: 'تیم شبکه مجتمع',
    summary: 'پایه‌ای محکم برای ورود حرفه‌ای به شبکه، زیرساخت و مسیرهای تخصصی بعدی.',
    tags: ['CompTIA', 'Network', 'Infrastructure'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'web', slug: 'web-design', title: 'طراحی و توسعه وب', english: 'WEB DESIGN', category: 'programming',
    level: 'از صفر', duration: '۶۴ ساعت', schedule: 'ثبت‌نام فعال', teacher: 'تیم طراحی وب',
    summary: 'طراحی رابط، HTML/CSS/JavaScript و ساخت پروژه‌های مدرن وب از صفر.',
    tags: ['HTML', 'CSS', 'JavaScript'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'cyber', slug: 'cyber-security', title: 'امنیت شبکه و Cyber Security', english: 'CYBER SECURITY', category: 'security',
    level: 'متوسط', duration: '۵۶ ساعت', schedule: 'ثبت‌نام فعال', teacher: 'تیم امنیت شبکه',
    summary: 'مبانی امنیت، ارزیابی ریسک و سناریوهای عملی برای ورود اصولی به دنیای امنیت.',
    tags: ['Security', 'Network', 'Linux'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'photoshop', slug: 'photoshop', title: 'Adobe Photoshop', english: 'PHOTOSHOP', category: 'design',
    level: 'از پایه', duration: '۴۰ ساعت', schedule: 'چند نوبت در هفته', teacher: 'تیم گرافیک مجتمع',
    summary: 'کار با ابزارهای اصلی فتوشاپ، طراحی کاربردی و آماده‌سازی نمونه‌کارهای واقعی.',
    tags: ['Adobe', 'Design', 'Portfolio'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'icdl', slug: 'icdl', title: 'مهارت‌های ICDL', english: 'ICDL', category: 'foundation',
    level: 'مقدماتی', duration: '۴۰ ساعت', schedule: 'چند نوبت در هفته', teacher: 'اساتید مجتمع',
    summary: 'مهارت‌های ضروری کار با کامپیوتر و ابزارهای اداری برای تحصیل و محیط کار.',
    tags: ['Windows', 'Office', 'Digital Skills'], featured: true, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'accounting', slug: 'accounting', title: 'حسابداری کاربردی', english: 'ACCOUNTING', category: 'finance',
    level: 'مقدماتی تا کاربردی', duration: '۴۸ ساعت', schedule: 'ثبت‌نام فعال', teacher: 'تیم حسابداری مجتمع',
    summary: 'آموزش اصول حسابداری و کار با ابزارهای پرکاربرد برای ورود آماده‌تر به محیط کار.',
    tags: ['Accounting', 'Office', 'Market'], featured: false, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  },
  {
    id: 'kids', slug: 'kids-computer', title: 'کامپیوتر برای کودکان', english: 'KIDS COMPUTER', category: 'kids',
    level: 'ویژه کودکان', duration: '۳۲ ساعت', schedule: 'ترم‌های گروهی', teacher: 'تیم آموزش کودک',
    summary: 'آشنایی خلاقانه و مرحله‌به‌مرحله با کامپیوتر، منطق و مهارت‌های دیجیتال برای کودکان.',
    tags: ['Kids', 'Digital Skills', 'Creative'], featured: false, published: true, price: 'تماس بگیرید', seats: 'ثبت‌نام باز'
  }
];

export const testimonials = [
  { name: 'دانش‌آموخته دوره شبکه', course: 'Network / Linux', text: 'تدریس مفهومی و فضای عملی باعث شد مطالب را فقط حفظ نکنم و واقعاً بفهمم.' },
  { name: 'دانش‌آموخته ICDL', course: 'ICDL', text: 'مهارت‌هایی که در دوره یاد گرفتم مستقیماً در کار و مسیر حرفه‌ای من استفاده شد.' },
  { name: 'دانش‌آموخته Cisco', course: 'CCNA', text: 'تجهیزات واقعی، سناریوهای عملی و پیگیری مدرس‌ها تفاوت اصلی این دوره بود.' }
];

export const stats = [
  { value: '۲۰+', label: 'سال تجربه آموزشی' },
  { value: '۱۵K+', label: 'دانش‌آموخته' },
  { value: '۷۰+', label: 'دوره تخصصی' },
  { value: '۲۰+', label: 'مدرس متخصص' }
];

export const studentData = {
  name: 'دانشجوی نمونه',
  activeCourses: [
    { title: 'Python', progress: 68, next: 'جلسه ۱۲ — توابع و ماژول‌ها', date: '۱۴۰۵/۰۵/۲۳' },
    { title: 'Network+', progress: 34, next: 'جلسه ۶ — Subnetting', date: '۱۴۰۵/۰۵/۲۵' }
  ],
  certificates: [
    { title: 'ICDL', code: 'DH-1404-2318', status: 'قابل استعلام' }
  ]
};
