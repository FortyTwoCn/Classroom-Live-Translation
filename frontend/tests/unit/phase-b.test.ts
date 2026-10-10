import {describe,it,expect,vi,afterEach} from 'vitest';
import {readFileSync,readdirSync} from 'node:fs';import {resolve} from 'node:path';
import QRCode from 'qrcode';import jsQR from 'jsqr';import {PNG} from 'pngjs';
import {messages,t,locale,missingMessageKeys} from '../../src/i18n';import {api,ApiError} from '../../src/api';
const files=(directory:string):string[]=>readdirSync(directory,{withFileTypes:true}).flatMap(entry=>entry.isDirectory()?files(resolve(directory,entry.name)):[resolve(directory,entry.name)]);
describe('complete product language resources',()=>{
 it('has a real translation for every literal UI key and PHP stable failure code',()=>{
  const keys=new Set<string>();for(const file of files(resolve('src')).filter(path=>/\.(vue|ts)$/.test(path)))for(const match of readFileSync(file,'utf8').matchAll(/\bt\(["']([^"']+)["']/g))if(!match[1]!.endsWith('_'))keys.add(match[1]!);
  for(const file of [...files(resolve('../app')),...files(resolve('../routes'))].filter(path=>path.endsWith('.php'))){const source=readFileSync(file,'utf8');for(const match of source.matchAll(/(?:new Failure\(|['"]code['"]\s*=>\s*)['"]([a-z][a-z0-9_]+)['"]/g))keys.add(`error_${match[1]}`);}
  missingMessageKeys.clear();for(const language of ['zh-CN','en','uk']as const){locale.value=language;for(const key of keys)t(key);}expect([...missingMessageKeys]).toEqual([]);
  expect(Object.keys(messages.en).sort()).toEqual(Object.keys(messages['zh-CN']).sort());expect(Object.keys(messages.uk).sort()).toEqual(Object.keys(messages['zh-CN']).sort());
 });
});
describe('QR content, not just an image pattern',()=>{
 for(const url of ['https://class.example/lessons/2','https://class.example/s/8b9d0123f89a56789012345abcdef000000'])it(`decodes a locally generated ${url.includes('/s/')?'private':'public'} QR to exactly the stable lesson link`,async()=>{const bytes=await QRCode.toBuffer(url,{width:640,margin:4,errorCorrectionLevel:'M'});const png=PNG.sync.read(bytes);const decoded=jsQR(new Uint8ClampedArray(png.data),png.width,png.height);expect(decoded?.data).toBe(url);});
});
afterEach(()=>vi.unstubAllGlobals());
describe('API mutation contracts',()=>{
 it('retains conflict details and sends actual PATCH',async()=>{const fetch=vi.fn().mockResolvedValue(new Response(JSON.stringify({code:'subtitle_version_conflict',params:{latest_units:[{id:3,version:2,text:'new'}]}}),{status:409}));vi.stubGlobal('fetch',fetch);api.csrf='test-csrf';try{await api.request('/api/lessons/1/subtitles',{changes:[]},'PATCH');expect.fail('must reject');}catch(error){expect(error).toBeInstanceOf(ApiError);expect((error as ApiError).params.latest_units).toEqual([{id:3,version:2,text:'new'}]);}expect(fetch.mock.calls[0]![1].method).toBe('PATCH');expect(fetch.mock.calls[0]![1].headers['X-CSRF-TOKEN']).toBe('test-csrf');});
 it('leaves multipart boundary to the browser for actual Excel upload',async()=>{const fetch=vi.fn().mockResolvedValue(new Response(JSON.stringify({import:{status:'preview'}})));vi.stubGlobal('fetch',fetch);const form=new FormData();form.append('duplicate_policy','skip');await api.request('/api/admin/imports',form);expect(fetch.mock.calls[0]![1].body).toBe(form);expect(fetch.mock.calls[0]![1].headers['Content-Type']).toBeUndefined();});
});
