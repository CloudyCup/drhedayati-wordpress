import React, { useEffect, useState } from 'react';
import PreviewBar from './components/PreviewBar';
import { FrameworkHome, AxisHome, NavigatorHome } from './components/Concepts';
import { AboutPage, BlogPage, CertificatesPage, ConsultPage, ContactPage, CourseDetail, CoursesPage } from './components/Pages';
import { AdminPanel, StudentPanel } from './components/Panels';
import { initialCourses } from './data/siteData';

const validConcepts = ['framework','axis','navigator'];

function parseHash() {
  const raw = window.location.hash.replace(/^#\/?/, '') || 'home';
  return decodeURIComponent(raw);
}

export default function App() {
  const [route, setRoute] = useState(parseHash());
  const [concept, setConcept] = useState(() => {
    const saved = localStorage.getItem('dh-concept-v2');
    return validConcepts.includes(saved) ? saved : 'framework';
  });
  const [theme, setTheme] = useState(() => localStorage.getItem('dh-theme') || 'light');
  const [courses, setCoursesState] = useState(() => {
    try { return JSON.parse(localStorage.getItem('dh-courses-v2')) || initialCourses; } catch { return initialCourses; }
  });

  const setCourses = updater => setCoursesState(prev => {
    const next = typeof updater === 'function' ? updater(prev) : updater;
    localStorage.setItem('dh-courses-v2', JSON.stringify(next));
    return next;
  });

  useEffect(() => {
    const onHash = () => setRoute(parseHash());
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);
  useEffect(() => { document.documentElement.dataset.theme = theme; localStorage.setItem('dh-theme', theme); }, [theme]);
  useEffect(() => { localStorage.setItem('dh-concept-v2', concept); }, [concept]);

  const navigate = next => {
    window.location.hash = `#/${next}`;
    setRoute(next);
    window.scrollTo({top:0,behavior:'smooth'});
  };

  let page;
  if (route === 'admin') page = <AdminPanel courses={courses} setCourses={setCourses} navigate={navigate}/>;
  else if (route === 'student') page = <StudentPanel navigate={navigate}/>;
  else if (route.startsWith('course:')) page = <CourseDetail course={courses.find(c=>c.slug===route.split(':')[1])} navigate={navigate} concept={concept}/>;
  else if (route.startsWith('courses')) page = <CoursesPage courses={courses} navigate={navigate} concept={concept} category={route.split(':')[1]}/>;
  else if (route === 'about') page = <AboutPage navigate={navigate} concept={concept}/>;
  else if (route === 'certificates') page = <CertificatesPage navigate={navigate} concept={concept}/>;
  else if (route === 'blog') page = <BlogPage navigate={navigate} concept={concept}/>;
  else if (route === 'contact') page = <ContactPage navigate={navigate} concept={concept}/>;
  else if (route === 'consult') page = <ConsultPage navigate={navigate} concept={concept}/>;
  else if (concept === 'axis') page = <AxisHome courses={courses} navigate={navigate}/>;
  else if (concept === 'navigator') page = <NavigatorHome courses={courses} navigate={navigate}/>;
  else page = <FrameworkHome courses={courses} navigate={navigate}/>;

  return <div className={`app concept-${concept}`}><PreviewBar concept={concept} setConcept={setConcept} theme={theme} setTheme={setTheme} navigate={navigate}/>{page}</div>;
}
