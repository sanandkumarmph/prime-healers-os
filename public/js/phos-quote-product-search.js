(() => {
    'use strict';
    const list = document.getElementById('quotation-lines');
    if (!list) return;
    function enhance(select) {
        if (select.dataset.searchReady) return;
        select.dataset.searchReady = '1';
        const holder = select.closest('.sw-field'), label = holder.querySelector('label');
        const input = document.createElement('input'), panel = document.createElement('div'), count = document.createElement('span');
        input.type = 'search'; input.id = select.id + '-search'; input.autocomplete = 'off'; input.placeholder = 'Search product, brand, model or SKU';
        input.setAttribute('role','combobox'); input.setAttribute('aria-autocomplete','list'); input.setAttribute('aria-expanded','false');
        panel.id = select.id + '-results'; panel.className = 'sw-product-results'; panel.hidden = true; panel.setAttribute('role','listbox');
        input.setAttribute('aria-controls',panel.id); count.className = 'sw-product-match-count'; count.setAttribute('role','status');
        select.classList.add('sw-product-native'); select.tabIndex = -1; select.setAttribute('aria-hidden','true');
        label.htmlFor = input.id; select.after(input,panel,count);
        let active = -1;
        const selectedText = () => select.value ? select.selectedOptions[0]?.textContent || '' : '';
        const close = () => { panel.hidden = true; input.setAttribute('aria-expanded','false'); input.removeAttribute('aria-activedescendant'); input.value = selectedText(); count.textContent = ''; };
        const validity = () => {
            const selected = select.selectedOptions[0];
            const invalid = !select.value || !selected || selected.disabled || !!select.validity.customError;
            input.setCustomValidity(invalid ? (select.validationMessage || 'Choose a product from the list.') : '');
            input.setAttribute('aria-required',select.required ? 'true' : 'false');
        };
        const choose = option => {
            select.value = option.value; select.dispatchEvent(new Event('change',{bubbles:true}));
            input.value = selectedText(); close(); validity(); input.focus(); close();
        };
        const render = () => {
            panel.replaceChildren(); active = -1;
            const query = input.value.trim().toLocaleLowerCase();
            const row = select.closest('[data-quote-line]');
            const type = document.getElementById('kind')?.value === 'sale' ? 'sale' : row.querySelector('[data-line-field=line_type]').value;
            const matches = Array.from(select.options).filter(o => o.value && !o.disabled && !o.hidden && o.dataset[type==='sale'?'canSell':'canRent']==='1' && (!query || (o.dataset.search || o.textContent).toLocaleLowerCase().includes(query)));
            for (const [i,option] of matches.slice(0,60).entries()) {
                const button = document.createElement('button'); button.type = 'button'; button.id = panel.id+'-'+i;
                button.textContent = option.textContent; button.setAttribute('role','option'); button.setAttribute('aria-selected',option.value===select.value ? 'true' : 'false');
                button.addEventListener('click',()=>choose(option)); panel.append(button);
            }
            if (!matches.length) { const empty=document.createElement('p'); empty.textContent='No matching products for this charge type.'; panel.append(empty); }
            count.textContent = matches.length>60 ? 'Showing 60 matches. Type more to narrow the list.' : matches.length+' matching products';
            panel.hidden=false; input.setAttribute('aria-expanded','true');
        };
        input.value=selectedText(); validity();
        input.addEventListener('focus',()=>{input.value='';render();}); input.addEventListener('input',event=>{event.stopPropagation();render();});
        input.addEventListener('change',event=>event.stopPropagation());
        input.addEventListener('keydown',event=>{
            if(event.key==='Escape'){event.preventDefault();close();return;}
            if(event.key==='Tab'){close();return;}
            if(['ArrowDown','ArrowUp'].includes(event.key)){
                event.preventDefault();if(panel.hidden)render();const buttons=Array.from(panel.querySelectorAll('button'));
                if(!buttons.length)return;
                active=(active+(event.key==='ArrowDown'?1:-1)+buttons.length)%buttons.length;
                buttons.forEach((b,i)=>b.setAttribute('aria-selected',i===active?'true':'false'));
                input.setAttribute('aria-activedescendant',buttons[active].id);buttons[active].scrollIntoView({block:'nearest'});
            }else if(event.key==='Enter'&&!panel.hidden){event.preventDefault();if(active>=0)panel.querySelectorAll('button')[active]?.click();}
        });
        input.addEventListener('blur',()=>{setTimeout(()=>{if(!holder.contains(document.activeElement))close();},0);});
        document.addEventListener('pointerdown',event=>{if(!holder.contains(event.target))close();});
        select.addEventListener('change',()=>{input.value=selectedText();validity();});
        select.addEventListener('invalid',event=>{event.preventDefault();validity();input.focus();input.reportValidity();});
        new MutationObserver(()=>{validity();if(!panel.hidden)render();else input.value=selectedText();}).observe(select,{subtree:true,attributes:true,attributeFilter:['disabled','hidden']});
    }
    const scan=()=>list.querySelectorAll('[data-quote-product-picker]').forEach(enhance);
    scan();new MutationObserver(scan).observe(list,{childList:true});
})();
