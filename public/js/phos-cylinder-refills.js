(() => {
 'use strict';
 const dialog=document.querySelector('[data-cylinder-refill-dialog]'),body=dialog?.querySelector('[data-cylinder-refill-body]');
 const feedback=dialog?.querySelector('[data-cylinder-refill-feedback]');let opener;
 const showFeedback=(text,isError=false)=>{feedback.classList.toggle('is-error',isError);feedback.textContent=text;};
 const assignment=root=>root.querySelectorAll('[data-refill-assignment]').forEach(group=>{
  const type=group.querySelector('[data-refill-assignment-type]'),target=group.querySelector('[data-refill-assignment-target]');
  [...target.options].forEach(option=>{if(!option.value)return;option.hidden=option.disabled=option.dataset.type!==type.value;});
  if(target.selectedOptions[0]?.disabled)target.value='';
  const manual=type.value==='third_party'&&target.value==='manual',fields=group.querySelector('[data-refill-third-party]');fields.hidden=!manual;
  fields.querySelectorAll('input').forEach(input=>{input.disabled=!manual;input.required=manual&&input.name==='third_party_name';});
  group.querySelector('[data-refill-assignment-note]').textContent=target.selectedOptions[0]?.dataset.login==='0'?'No PHOS login is assigned to this provider. Your operations team records delivery and empty pickup in this popup.':'Internal team lists active delivery logins only. Choose Vendor or Third party for an external provider.';
 });
 assignment(document);
 const updatePending=data=>{if(Number.isInteger(data.pending_empty))document.querySelectorAll('[data-refill-pending-empty]').forEach(el=>{el.hidden=data.pending_empty===0;el.textContent=`${data.pending_empty} empty cylinder(s) still need to be received back.`;});};
 const error=(root,text)=>{if(root===body&&feedback){showFeedback(text,true);return;}let el=root.querySelector('[data-cylinder-refill-errors]');if(!el){el=document.createElement('div');el.className='cr-errors';el.setAttribute('role','alert');root.prepend(el);}el.hidden=false;el.textContent=text;el.scrollIntoView({block:'nearest'});};
 const json=async response=>{if(response.redirected||!response.headers.get('content-type')?.includes('application/json'))throw new Error('Your session may have expired. Refresh the page and sign in again.');const data=await response.json();if(!response.ok)throw new Error(data.errors?Object.values(data.errors).flat().join(' '):data.message||'The refill could not be saved.');return data;};
 document.addEventListener('click',async event=>{
  const link=event.target.closest('[data-open-cylinder-refill]');
  if(link&&dialog){event.preventDefault();opener=link;if(feedback)showFeedback('');body.textContent='Loading cylinder refill…';dialog.showModal();
   try{const url=new URL(link.href,location.href);if(url.origin!==location.origin)throw new Error('Invalid refill address.');const data=await json(await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin'}));body.innerHTML=data.html;assignment(body);updatePending(data);body.querySelector('select,input,button')?.focus();}catch(e){error(body,e.message);}return;}
  if(event.target.closest('[data-close-cylinder-refill]')){dialog?.close();opener?.focus();}
 });
 dialog?.addEventListener('close',()=>opener?.focus());
 document.addEventListener('change',event=>{if(event.target.matches('[data-refill-assignment-type],[data-refill-assignment-target]'))assignment(event.target.form);if(event.target.matches('.cr-book [name=product_id]')){const qty=event.target.form.querySelector('[name=quantity]'),issued=Number(event.target.selectedOptions[0]?.dataset.issued)||0;qty.max=Math.max(1,issued);qty.value=Math.min(Number(qty.value)||1,Number(qty.max));event.target.form.querySelector('[data-refill-issued]').textContent=`Previously issued: ${issued} cylinder(s)`;}});
 document.addEventListener('submit',async event=>{
  const form=event.target.closest('[data-cylinder-refill-form]');if(!form)return;
  const root=form.closest('[data-cylinder-refill-body]')||form.closest('[data-cylinder-task-content]');if(!root)return;
  event.preventDefault();if(form.dataset.saving==='true')return;
  const inPopup=root===body&&feedback;
  const submittedWithFocus=form.contains(document.activeElement);
  form.dataset.saving='true';const buttons=[...form.querySelectorAll('button[type=submit]')],data=new FormData(form);buttons.forEach(b=>b.disabled=true);
  try{
   // Progress forms have an input named "action", which shadows form.action.
   const url=new URL(form.getAttribute('action'),location.href);
   if(url.origin!==location.origin)throw new Error('Invalid refill address.');
   const result=await json(await fetch(url,{method:'POST',body:data,headers:{Accept:'application/json'},credentials:'same-origin'}));
   const fragment=document.createElement('template');fragment.innerHTML=result.html;
   const taskContent=root.matches('[data-cylinder-task-content]')&&fragment.content.querySelector('[data-cylinder-task-content]');
   const scrollTop=inPopup?dialog.scrollTop:0;
   const openSections=new Set([...root.querySelectorAll('details[data-refill-section][open]')].map(section=>section.dataset.refillSection));
   const message=fragment.content.querySelector('.cr-success');
   if(inPopup)message?.remove();
   root.replaceChildren(...(taskContent||fragment.content).childNodes);
   assignment(root);updatePending(result);
   root.querySelectorAll('details[data-refill-section]').forEach(section=>{section.open=openSections.has(section.dataset.refillSection);});
   if(inPopup){
    showFeedback(message?.textContent.trim()||'Refill updated.');
    if(submittedWithFocus&&(!dialog.contains(document.activeElement)||document.activeElement===dialog))feedback.focus({preventScroll:true});
    dialog.scrollTop=scrollTop;
   }else{
    root.querySelector('.cr-success')?.scrollIntoView({block:'nearest'});
   }
  }catch(e){error(root,e.message);form.dataset.saving='false';buttons.forEach(b=>b.disabled=false);}
 });
})();
