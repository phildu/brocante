const vm=require('vm'),fs=require('fs'),assert=require('assert');
const dir=require('path').join(__dirname,'..');
const calls=[];let product;
const store={apiBase:'https://x.test/s/api/annonces',token:'am_x'};
const noop=()=>{};
const chrome={storage:{local:{get:async(k)=>{const o={};(Array.isArray(k)?k:[k]).forEach(n=>o[n]=store[n]);return o;},set:async(o)=>Object.assign(store,o),remove:noop}},
 alarms:{create:noop,get:async()=>null,clear:noop,onAlarm:{addListener:noop}},runtime:{onMessage:{addListener:noop},onInstalled:{addListener:noop},onStartup:{addListener:noop}},
 tabs:{onRemoved:{addListener:noop},onUpdated:{addListener:noop}},action:{},sidePanel:{setPanelBehavior:async()=>{}},scripting:{},windows:{}};
const fetch=async(url,o={})=>{calls.push({url,method:o.method||'GET',body:o.body&&JSON.parse(o.body)});
 const data=/produit/.test(url)?{product}:{ok:true};return{ok:true,status:200,json:async()=>data}};
const ctx={chrome,fetch,console,setTimeout,clearTimeout,Date,Math,JSON,URL,Promise,importScripts:(f)=>vm.runInContext(fs.readFileSync(dir+'/'+f,'utf8'),ctx)};
ctx.globalThis=ctx;vm.createContext(ctx);
vm.runInContext(fs.readFileSync(dir+'/background.js','utf8')+';this.__t={compareWithPublished,hashFromNote}',ctx);
const T=ctx.__t,Q=ctx.AmQuality;
product={ref:'012',title:'Derbies cuir noir homme',description:'Belle paire en cuir',price:20,photos:[{url:'u1'}]};
(async()=>{
 const h=Q.fingerprint(product,'leboncoin');
 assert.strictEqual(T.hashFromNote('[h:'+h+'] published'),h);
 assert.strictEqual(await T.compareWithPublished('012','leboncoin',{note:'[h:'+h+'] published'}),'same');
 product={...product,price:25};
 assert.strictEqual(await T.compareWithPublished('012','leboncoin',{note:'[h:'+h+'] published'}),'changed');
 calls.length=0;
 assert.strictEqual(await T.compareWithPublished('012','leboncoin',{note:'published — vieux',listing_url:'https://l/1'}),'same');
 const m=calls.find(c=>/marquer/.test(c.url));assert.ok(m&&/^\[h:[0-9a-f]{16}\]/.test(m.body.note)&&m.body.listing_url==='https://l/1');
 console.log('sync-test OK');
})().catch(e=>{console.error(e);process.exit(1)});
