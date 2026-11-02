(() => {
 'use strict';
 const dialog=document.querySelector('[data-cylinder-refill-dialog]'),body=dialog?.querySelector('[data-cylinder-refill-body]');let opener;
 const updatePending=data=>{if(Number.isInteger(data.pending_empty))document.querySelectorAll('[data-refill-pending-empty]').forEach(el=>{el.hidden=data.pending_empty===0;el.textContent=`${data.pending_empty} empty cylinder(s) still need to be received back.`;});};
 const error=(root,text)=>{let el=root.querySelector('[data-cylinder-refill-errors]');if(!el){el=document.createElement('div');el.className='cr-errors';el.setAttribute('role','alert');root.prepend(el);}el.hidden=false;el.textContent=text;el.scrollIntoView({block:'nearest'});};
 const json=async response=>{if(response.redirected||!response.headers.get('content-type')?.includes('application/json'))throw new Error('Your session may have expired. Refresh the page and sign in again.');const data=await response.json();if(!response.ok)throw new Error(data.errors?Object.values(data.errors).flat().join(' '):data.message||'The refill could not be saved.');return data;};
 document.addEventListener('click',async event=>{
  const link=event.target.closest('[data-open-cylinder-refill]');
  if(link&&dialog){event.preventDefault();opener=link;body.textContent='Loading cylinder refill…';dialog.showModal();
   try{const url=new URL(link.href,location.href);if(url.origin!==location.origin)throw new Error('Invalid refill address.');const data=await json(await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin'}));body.innerHTML=data.html;updatePending(data);body.querySelector('select,input,button')?.focus();}catch(e){error(body,e.message);}return;}
  if(event.target.closest('[data-close-cylinder-refill]')){dialog?.close();opener?.focus();}
 });
 dialog?.addEventListener('close',()=>opener?.focus());
 document.addEventListener('change',event=>{if(event.target.matches('.cr-book [name=product_id]')){const qty=event.target.form.querySelector('[name=quantity]'),issued=Number(event.target.selectedOptions[0]?.dataset.issued)||0;qty.max=Math.max(1,issued);qty.value=Math.min(Number(qty.value)||1,Number(qty.max));event.target.form.querySelector('[data-refill-issued]').textContent=`Previously issued: ${issued} cylinder(s)`;}});
 document.addEventListener('submit',async event=>{
  const form=event.target.closest('[data-cylinder-refill-form]');if(!form)return;event.preventDefault();if(form.dataset.saving==='true')return;
  const root=form.closest('[data-cylinder-refill-body]')||form.closest('[data-cylinder-task-content]')||form.closest('.cr-standalone');if(!root)return;
  form.dataset.saving='true';const buttons=[...form.querySelectorAll('button[type=submit]')],data=new FormData(form);buttons.forEach(b=>b.disabled=true);
  try{const url=new URL(form.action,location.href);if(url.origin!==location.origin)throw new Error('Invalid refill address.');const result=await json(await fetch(url,{method:'POST',body:data,headers:{Accept:'application/json'},credentials:'same-origin'}));root.innerHTML=result.html;updatePending(result);root.querySelector('[role=status],.cr-success')?.scrollIntoView({block:'nearest'});}catch(e){error(root,e.message);form.dataset.saving='false';buttons.forEach(b=>b.disabled=false);}
 });
})();
