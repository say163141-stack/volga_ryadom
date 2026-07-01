/* ============ ВОЛГА РЯДОМ — общий скрипт ============ */
/* Адреса бэкенда n8n. Если путь вебхука другой — поменяйте здесь. */
const N8N_BASE = "https://say163141.app.n8n.cloud";
const BOOKING_WEBHOOK = N8N_BASE + "/webhook/volga-booking-create"; // приём заявки (расходует execution в n8n)
/* Занятые даты читаются НАПРЯМУЮ из публичного Google Sheets (без n8n) —
   так просмотр календаря не тратит execution-квоту n8n. Расходуется только бронирование. */
const BUSY_CALENDAR_CSV = "https://docs.google.com/spreadsheets/d/1B3WqP-4xF3rgqtBeoYLwNJgzBQfuDcMqesXrgxcR_mI/gviz/tq?tqx=out:csv&gid=1360421097";
const TELEGRAM_LINK = "https://t.me/volga_ryadom_bot";

/* Простой парсер CSV для гугл-таблиц (значения в кавычках, запятая-разделитель) */
function parseCSV(text){
  const rows=[]; let row=[]; let cur=''; let inQuotes=false;
  for(let i=0;i<text.length;i++){
    const c=text[i];
    if(inQuotes){
      if(c==='"'){
        if(text[i+1]==='"'){ cur+='"'; i++; } else { inQuotes=false; }
      } else cur+=c;
    } else {
      if(c==='"') inQuotes=true;
      else if(c===','){ row.push(cur); cur=''; }
      else if(c==='\n'||c==='\r'){
        if(c==='\r'&&text[i+1]==='\n') i++;
        row.push(cur); cur=''; rows.push(row); row=[];
      } else cur+=c;
    }
  }
  if(cur!==''||row.length){ row.push(cur); rows.push(row); }
  return rows.filter(r=>r.length>1||r[0]!=='');
}

/* ---------- Мобильное меню ---------- */
(function(){
  const toggle = document.getElementById('navToggle');
  const menu = document.getElementById('navMobile');
  if(!toggle || !menu) return;
  toggle.addEventListener('click', ()=>{
    const open = menu.hasAttribute('hidden');
    if(open){ menu.removeAttribute('hidden'); menu.style.display='flex'; }
    else { menu.setAttribute('hidden',''); menu.style.display='none'; }
    toggle.setAttribute('aria-expanded', String(open));
    toggle.querySelector('.material-symbols-outlined').textContent = open ? 'close' : 'menu';
  });
})();

/* ---------- Карусель галереи ---------- */
(function(){
  const track = document.getElementById('carouselTrack');
  if(!track) return;
  const slides = Array.from(track.children);
  const prev = document.getElementById('carouselPrev');
  const next = document.getElementById('carouselNext');
  const dotsWrap = document.getElementById('carouselDots');

  function slideWidth(){ return slides[0].getBoundingClientRect().width + 16; }
  function current(){ return Math.round(track.scrollLeft / slideWidth()); }
  function goTo(i){
    i = Math.max(0, Math.min(slides.length-1, i));
    track.scrollTo({left: i*slideWidth(), behavior:'smooth'});
  }
  prev && prev.addEventListener('click', ()=>goTo(current()-1));
  next && next.addEventListener('click', ()=>goTo(current()+1));

  // точки
  if(dotsWrap){
    slides.forEach((_,i)=>{
      const b=document.createElement('button');
      b.setAttribute('aria-label','Фото '+(i+1));
      b.addEventListener('click',()=>goTo(i));
      dotsWrap.appendChild(b);
    });
    const dots=Array.from(dotsWrap.children);
    function syncDots(){
      const c=current();
      dots.forEach((d,i)=>d.classList.toggle('active', i===c));
    }
    track.addEventListener('scroll', ()=>{ window.requestAnimationFrame(syncDots); }, {passive:true});
    syncDots();
  }
})();

/* ---------- Утилиты дат ---------- */
function ymd(d){
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}
function parseYMD(s){ const [y,m,d]=s.split('-').map(Number); return new Date(y,m-1,d); }
function addDays(d,n){ const x=new Date(d); x.setDate(x.getDate()+n); return x; }
const MONTHS=['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

/* ---------- Календарь занятых дат + выбор диапазона ---------- */
(function(){
  const calGrid=document.getElementById('calGrid');
  if(!calGrid) return;
  const calTitle=document.getElementById('calTitle');
  const calStatus=document.getElementById('calStatus');
  const calPrev=document.getElementById('calPrev');
  const calNext=document.getElementById('calNext');
  const inCheckIn=document.getElementById('checkIn');
  const inCheckOut=document.getElementById('checkOut');
  const houseSelect=document.getElementById('houseSelect');

  const today=new Date(); today.setHours(0,0,0,0);
  let view=new Date(today.getFullYear(), today.getMonth(), 1);
  let busy=new Set();         // занятые ночи (YMD) для выбранного домика
  let selStart=null, selEnd=null;

  // Поддержка глубокой ссылки ?house=house_2
  if(houseSelect){
    const params=new URLSearchParams(location.search);
    const houseParam=params.get('house');
    if(houseParam && Array.from(houseSelect.options).some(o=>o.value===houseParam)){
      houseSelect.value=houseParam;
    }
  }

  function currentHouse(){ return houseSelect ? houseSelect.value : 'house_1'; }

  function loadBusyDates(){
    selStart=null; selEnd=null;
    inCheckIn.value=''; inCheckOut.value='';
    calStatus.textContent='Загружаем занятые даты…';
    busy=new Set();
    render();
    const house=currentHouse();
    fetch(BUSY_CALENDAR_CSV+'&_='+Date.now(), {method:'GET', cache:'no-store'})
      .then(r=> r.ok ? r.text() : Promise.reject(r.status))
      .then(text=>{
        const rows=parseCSV(text);
        const header=rows.shift()||[];
        const idx={object_id:header.indexOf('object_id'), check_in:header.indexOf('check_in'), check_out:header.indexOf('check_out'), status:header.indexOf('status')};
        const bookings=rows.map(r=>({
          object_id:r[idx.object_id],
          check_in:r[idx.check_in],
          check_out:r[idx.check_out],
          status:r[idx.status]
        })).filter(b=>b.object_id===house);
        busy=new Set(collectFromRanges(bookings));
        calStatus.textContent = busy.size ? 'Занятые даты этого домика отмечены и недоступны.' : 'У этого домика свободны все даты.';
        render();
      })
      .catch(()=>{
        calStatus.textContent='Не удалось загрузить занятые даты — выбор доступен, занятость подтвердим в Telegram.';
        render();
      });
  }

  loadBusyDates();
  houseSelect && houseSelect.addEventListener('change', loadBusyDates);

  function collectFromRanges(rows){
    const out=[];
    rows.forEach(r=>{
      const st=(r.status||'').toLowerCase();
      if(st && !['pending_payment','confirmed'].includes(st)) return;
      if(!r.check_in||!r.check_out) return;
      let d=parseYMD(r.check_in); const end=parseYMD(r.check_out);
      while(d<end){ out.push(ymd(d)); d=addDays(d,1); }
    });
    return out;
  }

  function render(){
    calTitle.textContent = MONTHS[view.getMonth()]+' '+view.getFullYear();
    calGrid.innerHTML='';
    const firstDay=new Date(view.getFullYear(),view.getMonth(),1);
    let startWeekday=firstDay.getDay(); startWeekday=(startWeekday+6)%7; // Пн=0
    const daysInMonth=new Date(view.getFullYear(),view.getMonth()+1,0).getDate();

    for(let i=0;i<startWeekday;i++){
      const e=document.createElement('div'); e.className='cal-cell empty'; calGrid.appendChild(e);
    }
    for(let day=1;day<=daysInMonth;day++){
      const date=new Date(view.getFullYear(),view.getMonth(),day);
      const key=ymd(date);
      const cell=document.createElement('button');
      cell.type='button'; cell.className='cal-cell'; cell.textContent=day;
      if(date<today){ cell.classList.add('past'); cell.disabled=true; }
      else if(busy.has(key)){ cell.classList.add('busy'); cell.disabled=true; cell.title='Занято'; }
      else {
        if(selStart && key===ymd(selStart)) cell.classList.add('sel');
        if(selEnd && key===ymd(selEnd)) cell.classList.add('sel');
        if(selStart && selEnd && date>selStart && date<selEnd) cell.classList.add('in-range');
        cell.addEventListener('click',()=>pick(date));
      }
      calGrid.appendChild(cell);
    }
    calPrev.disabled = (view.getFullYear()===today.getFullYear() && view.getMonth()===today.getMonth());
  }

  function rangeHasBusy(a,b){
    let d=new Date(a);
    while(d<b){ if(busy.has(ymd(d))) return true; d=addDays(d,1); }
    return false;
  }

  function pick(date){
    if(!selStart || (selStart && selEnd)){
      selStart=date; selEnd=null;
    } else if(date<=selStart){
      selStart=date; selEnd=null;
    } else {
      if(rangeHasBusy(selStart,date)){
        calStatus.textContent='В выбранном диапазоне есть занятые даты. Выберите другой период.';
        selStart=date; selEnd=null;
      } else {
        selEnd=date;
        calStatus.textContent='Даты выбраны. Заполните контакты и отправьте заявку.';
      }
    }
    inCheckIn.value = selStart?ymd(selStart):'';
    inCheckOut.value = selEnd?ymd(selEnd):'';
    render();
  }

  calPrev.addEventListener('click',()=>{ view=new Date(view.getFullYear(),view.getMonth()-1,1); render(); });
  calNext.addEventListener('click',()=>{ view=new Date(view.getFullYear(),view.getMonth()+1,1); render(); });
})();

/* ---------- Отправка формы бронирования ---------- */
(function(){
  const form=document.getElementById('bookingForm');
  if(!form) return;
  const note=document.getElementById('formNote');
  const btn=document.getElementById('bookingSubmit');
  const houseSelectEl=document.getElementById('houseSelect');

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    note.className='form-note'; note.textContent='';
    const payload={
      object_id:houseSelectEl ? houseSelectEl.value : 'house_1',
      check_in:form.check_in.value,
      check_out:form.check_out.value,
      guest_name:form.guest_name.value.trim(),
      phone:form.phone.value.trim(),
      guests:Number(form.guests.value),
      extra_bed:form.extra_bed ? Number(form.extra_bed.value) : 0,
      comment:form.comment.value.trim()
    };
    if(!payload.check_in||!payload.check_out){ note.classList.add('err'); note.textContent='Выберите даты заезда и выезда в календаре.'; return; }
    if(!payload.guest_name){ note.classList.add('err'); note.textContent='Укажите ваше имя.'; return; }
    if(!payload.phone){ note.classList.add('err'); note.textContent='Укажите телефон для связи.'; return; }

    btn.disabled=true; const orig=btn.textContent; btn.textContent='Отправляем…';
    try{
      const res=await fetch(BOOKING_WEBHOOK,{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload)
      });
      let data={}; try{ data=await res.json(); }catch(_){}
      if(res.status===200 && data.success){
        note.classList.add('ok');
        note.textContent='Заявка принята! Мы свяжемся с вами в Telegram для подтверждения.';
        form.reset();
      } else if(res.status===409 || data.available===false){
        note.classList.add('err');
        note.textContent='Эти даты уже заняты. Пожалуйста, выберите другой период.';
      } else {
        note.classList.add('err');
        note.textContent='Не удалось отправить заявку. Напишите нам в Telegram — поможем.';
      }
    }catch(err){
      note.classList.add('err');
      note.innerHTML='Сервер недоступен. Напишите нам в <a href="'+TELEGRAM_LINK+'" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline">Telegram</a>.';
    }finally{
      btn.disabled=false; btn.textContent=orig;
    }
  });
})();
