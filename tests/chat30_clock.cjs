const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(process.argv[2]||'outputs/morshed/morshedapp.ir/assets/chat25.js','utf8');
function room({until=1800,duration=1800,state='confirmed',wall=0}={}){
 let monotonic=0,wallClock=wall;const server=1793280000,handlers={},ticks=[];let poll;
 const node=()=>({textContent:'',hidden:false,disabled:false,addEventListener(){},querySelectorAll(){return[];}});
 const timer=node(),input=node(),send=node(),form={...node(),querySelector:s=>s==='textarea'?input:send},stream={...node(),scrollHeight:0,scrollTop:0,clientHeight:0,children:[],insertBefore(){},prepend(){}},nodes={'[data-chat-stream]':stream,'[data-chat-form]':form,'[data-chat-state]':node(),'[data-chat-send-status]':node(),'[data-chat-timer]':timer,'[data-chat-older]':node(),'[data-chat-empty]':node()};
 const root={dataset:{now:String(server),start:String(server+until),end:String(server+until+duration),canSend:'1',state,id:'local',poll:'/poll'},querySelector:s=>nodes[s],querySelectorAll:()=>[]};
 const doc={hidden:false,querySelector:()=>root,addEventListener:(type,fn)=>handlers[type]=fn,createDocumentFragment:()=>({children:[],append(){}})};
 let response={chat:{server_time:server,starts_at:server+until,ends_at:server+until+duration,booking_state:state,can_send:true,reason:'server',messages:[],more:false}};
 const context={document:doc,window:{addEventListener:(type,fn)=>handlers[type]=fn},location:{origin:'https://local.test'},performance:{now:()=>monotonic},Date:class extends Date{static now(){return wallClock;}},Intl,URL,AbortController,setInterval:fn=>{ticks.push(fn);return ticks.length;},clearInterval(){},setTimeout:(fn)=>{if(fn.name==='poll')poll=fn;return 1;},clearTimeout(){},fetch:async()=>({ok:true,json:async()=>response})};
 vm.runInNewContext(source,context);
 return {timer,input,send,handlers,tick(ms){monotonic+=ms;ticks.at(-1)();},jump(ms){wallClock+=ms;},async sync(delta){response.chat.server_time=server+delta;await poll();},get text(){return timer.textContent;}};
}
(async()=>{
 let r=room({wall:Date.now()+34*3600000});assert.equal(r.text,'تا شروع: ۳۰ دقیقه');r.jump(-68*3600000);r.tick(1000);assert.equal(r.text,'تا شروع: ۲۹ دقیقه و ۵۹ ثانیه');
 r=room({until:1242});assert.equal(r.text,'تا شروع: ۲۰ دقیقه و ۴۲ ثانیه');assert(!r.text.includes('۲۰۴۲'));
 r=room({until:2042*60});assert.equal(r.text,'تا شروع: ۱ روز و ۱۰ ساعت و ۲ دقیقه');
 r=room({until:2});r.tick(2000);assert.equal(r.text,'باقی‌مانده: ۳۰ دقیقه');assert.equal(r.input.disabled,false);r.tick(1800000);assert.equal(r.text,'پایان جلسه');assert.equal(r.send.disabled,true);
 for(const state of ['pending','cancelled','rejected']){r=room({state});assert(!r.text.startsWith('تا شروع'));assert.equal(r.send.disabled,true);}
 r=room();await r.sync(1770);assert.equal(r.text,'تا شروع: ۳۰ ثانیه');r.tick(1000);assert.equal(r.text,'تا شروع: ۲۹ ثانیه');
 console.log('PASS chat countdown: explicit Persian units; 30-minute and 20:42 examples; long durations; start/end; pending/cancelled; server resync; device clock jumps');
})();
