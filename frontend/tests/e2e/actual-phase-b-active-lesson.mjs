import { chromium, expect } from '@playwright/test';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
if(!process.argv[2])throw new Error('Pass isolated phase-b-fixture.json.');
const fixture=JSON.parse(await readFile(process.argv[2],'utf8'));
const origin=process.env.ACTUAL_CAPTURE_URL||'http://localhost:18080';
const browser=await chromium.launch({channel:'msedge',headless:true});
const context=await browser.newContext();
const page=await context.newPage(),errors=[];
await page.addInitScript(()=>{window.__micRequests=0;const original=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);navigator.mediaDevices.getUserMedia=constraints=>{window.__micRequests++;return original(constraints)}});
page.on('pageerror',error=>errors.push(error.message));
try{
  await page.goto(`${origin}/courses`);
  await page.getByRole('button',{name:'登录',exact:true}).click();
  const dialog=page.getByRole('dialog');
  await dialog.getByLabel('学号',{exact:true}).fill(fixture.accounts.member.student_id);
  await dialog.getByLabel('密码',{exact:true}).fill(fixture.accounts.member.password);
  await dialog.getByRole('button',{name:'登录',exact:true}).click();
  await expect(page.getByRole('button',{name:'退出当前设备',exact:true})).toBeVisible();
  const identity=await(await context.request.get(`${origin}/api/me`)).json();
  const listing=await(await context.request.get(`${origin}/api/courses/${fixture.course_id}/lessons?status=all`)).json();
  let lesson=listing.lessons.find(item=>item.status!=='ended');
  if(!lesson){
    const created=await context.request.post(`${origin}/api/courses/${fixture.course_id}/lessons`,{headers:{'X-CSRF-TOKEN':identity.csrf_token},data:{name:'Explicit synthetic ready-only UI fixture',idempotency_key:crypto.randomUUID()}});
    expect(created.ok()).toBe(true);lesson=(await created.json()).lesson;
  }
  await page.goto(`${origin}/courses/${fixture.course_id}/lessons`);
  await page.getByRole('button',{name:/新建课堂/}).click();
  await page.getByRole('dialog').getByRole('button',{name:'创建',exact:true}).click();
  await expect(page.getByRole('dialog').getByRole('button',{name:'打开已有课堂',exact:true})).toBeVisible();
  await page.getByRole('dialog').getByRole('button',{name:'打开已有课堂',exact:true}).click();
  await expect(page).toHaveURL(`${origin}/lessons/${lesson.id}`);
  await expect(page.getByRole('dialog')).toHaveCount(0);
  expect(await page.evaluate(()=>window.__micRequests)).toBe(0);
  expect(errors).toEqual([]);
  const output=new URL('../artifacts/actual-phase-b/',import.meta.url);await mkdir(output,{recursive:true});
  await writeFile(new URL('active-lesson-results.json',output),JSON.stringify({classification:'ACTUAL_PHP_POSTGRESQL_EXISTING_LESSON_UI',status:'passed',testedAt:new Date().toISOString(),checks:['409 retains exact existing lesson ID','explicit button opens that lesson','no microphone request'],real_model:false,real_oss:false,real_microphone:false,jsErrors:errors},null,2));
  console.log('PASS actual 409 existing lesson navigation, zero microphone calls/errors');
}finally{await browser.close()}
