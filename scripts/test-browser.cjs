// Run against the disposable localhost fixture only. Requires Playwright.
const { chromium, request } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = process.env.HEDAYATI_LOCAL_RUNTIME || 'C:/Projects/hedayati-local-runtime';
const fixture = JSON.parse(fs.readFileSync(path.join(root, 'browser-fixture.json'), 'utf8').replace(/^\uFEFF/, ''));
const baseURL = 'http://127.0.0.1:18080';
const password = 'Local-QA-Only-9x24';
let passed = 0;
function check(name, condition) { assert.ok(condition, name); passed++; console.log('PASS ' + name); }
async function client(role) {
 const c = await request.newContext({baseURL});
 await c.get('/wp-login.php');
 const r = await c.post('/wp-login.php', { form: {log:'hdit_browser_'+role,pwd:password,'wp-submit':'Log In',testcookie:'1'}, maxRedirects:0 });
 check(role+' real login succeeds', r.status() === 302);
 return c;
}
function nonce(html, action) {
 const forms = html.match(/<form\b[\s\S]*?<\/form>/g) || [];
 const form = forms.find(f => f.includes('value="'+action+'"'));
 assert.ok(form, 'form exists: '+action);
 return (form.match(/name="_wpnonce" value="([^"]+)"/) || [])[1];
}
(async () => {
 const guest = await request.newContext({baseURL});
 for (const route of ['/account/','/panel/']) {
  const r=await guest.get(route,{maxRedirects:0}); check('guest denied '+route,r.status()===302); check('private redirect not cached '+route, /no-cache|no-store/.test(r.headers()['cache-control']||''));
 }
 for(const route of ['/','/about/','/contact/','/consult/','/teachers/',fixture.course_url]) { const r=await guest.get(route); check('public page responds '+route,r.status()===200 && !(await r.text()).includes('critical error')); }
 const s=await client('student'), b=await client('student_b'), t=await client('teacher'), ta=await client('teacher_assistant'), reception=await client('reception'), manager=await client('hedayati_manager');
 const account=await s.get('/account/'); check('student account loads',account.status()===200); check('account no-store',/no-store/.test(account.headers()['cache-control']||''));
 check('student blocked from staff panel',(await s.get('/panel/')).status()===403);
 check('student wp-admin redirects to account',(await s.get('/wp-admin/',{maxRedirects:0})).headers().location?.includes('/account/'));
 check('teacher cannot read student account',(await t.get('/account/')).status()===403);
 check('teacher cannot read unassigned class',(await t.get('/panel/?view=run&run_id='+fixture.other_run)).status()===403);
 const runHtml=await (await t.get('/panel/?view=run&run_id='+fixture.run)).text();
 check('teacher sees attendance form',runHtml.includes('hedayati_staff_attendance'));
 const taHtml=await (await ta.get('/panel/?view=run&run_id='+fixture.run)).text();
 check('TA has roster without attendance/session writes',!taHtml.includes('hedayati_staff_attendance')&&!taHtml.includes('hedayati_staff_session'));
 check('TA roster has no student email',!taHtml.includes('@example.test'));
 let r=await t.post('/wp-admin/admin-post.php',{form:{action:'hedayati_staff_attendance',_wpnonce:nonce(runHtml,'hedayati_staff_attendance'),session_id:String(fixture.session),['mark['+fixture.other_enrollment+']']:'present'},maxRedirects:0});
 check('foreign enrollment rejected before attendance write',r.status()===403);
 r=await t.post('/wp-admin/admin-post.php',{form:{action:'hedayati_staff_attendance',_wpnonce:nonce(runHtml,'hedayati_staff_attendance'),session_id:String(fixture.session),['mark['+fixture.enrollment+']']:'present'},maxRedirects:0}); check('teacher saves assigned attendance',r.status()===302);
 r=await ta.post('/wp-admin/admin-post.php',{form:{action:'hedayati_staff_attendance',_wpnonce:nonce(runHtml,'hedayati_staff_attendance'),session_id:String(fixture.session)},maxRedirects:0}); check('TA direct attendance POST denied',r.status()===403);
 const docHtml=await (await s.get('/account/?view=documents')).text();
 const pdf=Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF');
 r=await s.post('/wp-admin/admin-post.php',{multipart:{action:'hedayati_portal_document_upload',_wpnonce:nonce(docHtml,'hedayati_portal_document_upload'),doc_type:'other',document:{name:'fixture.pdf',mimeType:'application/pdf',buffer:pdf}},maxRedirects:0});
 check('real multipart upload redirects',r.status()===302);
 const afterDoc=await (await s.get('/account/?view=documents')).text();
 const downloadUrl=(afterDoc.match(/href="([^"]*action=hedayati_portal_document_download[^"]*)"/)||[])[1]?.replaceAll('&amp;','&').replaceAll('&#038;','&');
 check('uploaded document is listed',!!downloadUrl);
 r=await s.get(downloadUrl,{maxRedirects:0}); check('owner downloads exact uploaded bytes',r.status()===200&&(await r.body()).equals(pdf));
 const bHtml=await (await b.get('/account/?view=documents')).text();
 check('student B never sees A document',!bHtml.includes('hedayati_portal_document_download'));
 // A valid B upload nonce is intentionally not a download nonce: even stolen A nonces are unusable across sessions.
 r=await b.get(downloadUrl,{maxRedirects:0}); check('B cannot use A download nonce',r.status()===403);
 r=await s.post('/wp-admin/admin-post.php',{multipart:{action:'hedayati_portal_document_upload',_wpnonce:nonce(docHtml,'hedayati_portal_document_upload'),doc_type:'other',document:{name:'bad.pdf',mimeType:'application/pdf',buffer:Buffer.from('<script>alert(1)</script>')}},maxRedirects:0});
 const badHtml=await (await s.get('/account/?view=documents')).text(); check('HTML disguised as PDF rejected',badHtml.includes('hd-portal-notice-error'));
 const receptionHtml=await (await reception.get('/panel/?view=students')).text(); check('reception account form exists',receptionHtml.includes('hedayati_staff_student'));
 check('reception cannot open non-student dossier',(await reception.get('/panel/?view=students&student_id='+fixture.users.teacher)).status()===403);
 check('manager course editor accessible',(await manager.get('/wp-admin/post.php?post='+fixture.course+'&action=edit')).status()===200);
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 await page.goto(baseURL+'/'); await page.evaluate(()=>document.fonts.ready); await page.screenshot({path:path.join(root,'homepage-desktop.png'),fullPage:true});
 await page.goto(baseURL+'/about/'); await page.screenshot({path:path.join(root,'about-desktop.png'),fullPage:true});
 await page.setViewportSize({width:390,height:844}); await page.goto(baseURL+'/');
 check('mobile homepage no horizontal overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await page.screenshot({path:path.join(root,'homepage-mobile.png'),fullPage:true});
 await page.locator('#theme-toggle').click(); check('dark toggle works',await page.locator('html').getAttribute('data-theme')==='dark');
 await page.screenshot({path:path.join(root,'homepage-mobile-dark.png'),fullPage:true});
 await page.goto(baseURL+'/wp-login.php'); await page.locator('#user_login').fill('hdit_browser_teacher'); await page.locator('#user_pass').fill(password); await page.locator('#wp-submit').click(); await page.waitForURL('**/panel/');
 await page.goto(baseURL+'/panel/?view=run&run_id='+fixture.run); check('mobile staff no horizontal overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)); await page.screenshot({path:path.join(root,'teacher-mobile.png'),fullPage:true});
 check('no browser JavaScript errors',errors.length===0);
 await browser.close();
 for(const c of [guest,s,b,t,ta,reception,manager]) await c.dispose();
 console.log('HTTP/BROWSER TOTAL: '+passed+' passed');
})().catch(e=>{console.error(e);process.exit(1)});
