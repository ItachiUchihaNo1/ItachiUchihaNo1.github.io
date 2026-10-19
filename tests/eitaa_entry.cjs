const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const script=fs.readFileSync(require('node:path').join(__dirname,'../morshedapp.ir/assets/eitaa.js'),'utf8');
function fixture(responder,initData=''){
 let submit,assigned=[],requests=[],links=[],children=[];const button={disabled:true,removeAttribute(){}},status={classList:{remove(){}},replaceChildren(){children=[]},append(x){children.push(x)},textContent:''},field={value:''};
 const context={dataset:{csrf:'csrf',url:'/context',loginUrl:'/login-api'}};
 const form={elements:{purpose:{value:'login'}},querySelector(s){return s==='[data-eitaa-input]'?field:s==='[data-eitaa-status]'?status:null;},querySelectorAll(){return [button]},addEventListener(n,f){if(n==='submit')submit=f;}};
 const document={getElementById(id){return id==='eitaa-context'?context:null},querySelectorAll(s){return s==='form'?[form]:s==='[data-eitaa-input]'?[field]:[]},createElement(){return {addEventListener(n,f){this.click=f}}}};
 const location={origin:'https://morshedapp.ir',href:'https://morshedapp.ir/index.php?r=%2Flogin',assign(x){assigned.push(x)}};
 const fetch=async(url,options)=>{requests.push({url,body:JSON.parse(options.body)});return responder(url,options)};
 const window={fetch,Eitaa:{WebApp:{initData,ready(){},openEitaaLink(url){links.push(url)}}}};
 vm.runInNewContext(script,{window,document,location,fetch,AbortController,setTimeout,clearTimeout,URL,console});
 return {button,status,requests,assigned,links,children:()=>children,submit:()=>submit({preventDefault(){}})};
}
(async()=>{
 let f=fixture(async()=>({ok:true,json:async()=>({redirect:'/index.php?r=%2Faccount'})}));await f.submit();assert.equal(f.requests[0].body.init_data,'');assert.equal(f.assigned.length,1);assert.match(f.assigned[0],/account/);console.log('PASS missing SDK data still reaches verified server-side launch context');
 f=fixture(async()=>{throw new Error('offline')});await f.submit();assert.equal(f.button.disabled,false);assert.match(f.status.textContent,/ارتباط/);console.log('PASS failed login restores button and gives visible retry message');
 f=fixture(async()=>({ok:false,json:async()=>({error:{code:'ASSERTION_REPLAY'}})}));await f.submit();assert.equal(f.assigned.length,0);assert.match(f.status.textContent,/پایان/);assert.equal(f.children().length,1);f.children()[0].click({preventDefault(){}});assert.equal(f.links[0],'https://eitaa.com/morshed_app');console.log('PASS replay refusal stays enforced and offers official fresh-launch action');
 f=fixture(async()=>({ok:true,json:async()=>({launch_url:'https://eitaa.com/morshed_app'})}));await f.submit();assert.equal(f.links.length,1);assert.equal(f.button.disabled,false);console.log('PASS outside app launches official Eitaa destination without fabricating login');
 f=fixture(async()=>({ok:true,json:async()=>({redirect:'https://invalid.example'})}));await f.submit();assert.equal(f.assigned.length,0);assert.equal(f.button.disabled,false);console.log('PASS external response cannot redirect authentication to a foreign origin');
 f=fixture(async()=>({ok:true,json:async()=>({ok:true})}),'signed-data-fixture');await new Promise(resolve=>setImmediate(resolve));assert.deepEqual(f.requests.map(x=>x.url),['/context']);assert.equal(f.assigned.length,0);console.log('PASS public launch only captures context and never automatically signs in or requests contact');
 console.log('EITAA BROWSER RESULT: 6 passed, 0 failed');
})().catch(e=>{console.error(e);process.exit(1)});
