/* ============ ВОЛГА РЯДОМ — общий скрипт ============ */
/* Адреса бэкенда n8n. Если путь вебхука другой — поменяйте здесь. */
const N8N_BASE = "https://say163141.app.n8n.cloud";
const BOOKING_WEBHOOK = N8N_BASE + "/webhook/volga-booking-create"; // приём заявки
const BUSY_DATES_WEBHOOK = N8N_BASE + "/webhook/volga-busy-dates";   // занятые даты (GET)
const TELEGRAM_LINK = "https://t.me/volga_ryadom_bot";

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

  const today=new Date(); today.setHours(0,0,0,0);
  let view=new Date(today.getFullYear(), today.getMonth(), 1);
  let busy=new Set();         // занятые ночи (YMD)
  let selStart=null, selEnd=null;

  // Загрузка занятых дат
  fetch(BUSY_DATES_WEBHOOK, {method:'GET'})
    .then(r=> r.ok ? r.json() : Promise.reject(r.status))
    .then(data=>{
      // ожидаем {busy_dates:["2026-07-10", ...]} либо массив диапазонов {check_in,check_out,status}
      let dates=[];
      if(Array.isArray(data)) dates=collectFromRanges(data);
      else if(data && Array.isArray(data.busy_dates)) dates=data.busy_dates;
      else if(data && Array.isArray(data.bookings)) dates=collectFromRanges(data.bookings);
      busy=new Set(dates);
      calStatus.textContent = busy.size ? 'Занятые даты отмечены и недоступны.' : 'Свободны все даты.';
      render();
    })
    .catch(()=>{
      calStatus.textContent='Не удалось загрузить занятые даты — выбор доступен, занятость подтвердим в Telegram.';
      render();
    });

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

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    note.className='form-note'; note.textContent='';
    const payload={
      object_id:'house_1',
      check_in:form.check_in.value,
      check_out:form.check_out.value,
      guest_name:form.guest_name.value.trim(),
      phone:form.phone.value.trim(),
      guests:Number(form.guests.value),
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
