(function(){
  var script = document.currentScript;
  if(!script){ return; }

  var endpoint = (script.getAttribute('data-support-chat-endpoint') || '').replace(/\/$/, '');
  var siteKey = script.getAttribute('data-support-chat-key') || '';
  var langKey = (script.getAttribute('data-support-chat-lang') || document.documentElement.lang || 'ru').toLowerCase();
  if(langKey.indexOf('-') > -1){ langKey = langKey.split('-')[0]; }
  if(!endpoint || !siteKey){ return; }

  var i18n = {
    ru: {
      launcher: 'Чат', title: 'Поддержка', online: 'Онлайн', createChat: 'Создать чат',
      backToChat: 'Назад к чату', signIn: 'Вход', signInToManage: 'Войдите, чтобы управлять чатами',
      myChats: 'Мои чаты', yourName: 'Ваше имя', yourEmail: 'Ваш email',
      msgPlaceholder: 'Введите сообщение...', problem: 'Опишите проблему...',
      send: 'Отправить', email: 'Email', password: 'Пароль', login: 'Войти',
      register: 'Регистрация', createAccount: 'Создать аккаунт', noAccount: 'Нет аккаунта?',
      hasAccount: 'Уже есть аккаунт?', signup: 'Зарегистрироваться', edit: 'изменить', logout: 'выйти',
      profile: 'Профиль:', noMessages: 'Сообщений пока нет.', noChats: 'Чатов пока нет.',
      loginForChats: 'Для управления чатами аккаунта выполните вход.',
      enterMessage: 'Введите сообщение', fillNameEmail: 'Заполните имя и email',
      fillEmailPassword: 'Заполните email и пароль', invalidCredentials: 'Неверные данные',
      fillAllFields: 'Заполните все поля', registerError: 'Ошибка регистрации',
      sendError: 'Ошибка отправки', connectError: 'Ошибка соединения',
      support: 'Поддержка', you: 'Вы'
    },
    en: {
      launcher: 'Chat', title: 'Support', online: 'Online', createChat: 'Create chat',
      backToChat: 'Back to chat', signIn: 'Sign in', signInToManage: 'Sign in to manage chats',
      myChats: 'My chats', yourName: 'Your name', yourEmail: 'Your email',
      msgPlaceholder: 'Type a message...', problem: 'Describe your issue...',
      send: 'Send', email: 'Email', password: 'Password', login: 'Sign in',
      register: 'Register', createAccount: 'Create account', noAccount: 'No account?',
      hasAccount: 'Already have an account?', signup: 'Sign up', edit: 'edit', logout: 'log out',
      profile: 'Profile:', noMessages: 'No messages yet.', noChats: 'No chats yet.',
      loginForChats: 'Sign in to manage account chats.',
      enterMessage: 'Enter a message', fillNameEmail: 'Enter name and email',
      fillEmailPassword: 'Enter email and password', invalidCredentials: 'Invalid credentials',
      fillAllFields: 'Fill all fields', registerError: 'Registration failed',
      sendError: 'Failed to send', connectError: 'Connection error',
      support: 'Support', you: 'You'
    },
    et: {
      launcher: 'Vestlus', title: 'Tugi', online: 'Online', createChat: 'Loo vestlus',
      backToChat: 'Tagasi vestlusesse', signIn: 'Sisselogimine', signInToManage: 'Logi sisse, et vestlusi hallata',
      myChats: 'Minu vestlused', yourName: 'Sinu nimi', yourEmail: 'Sinu e-post',
      msgPlaceholder: 'Sisesta sõnum...', problem: 'Kirjelda probleemi...',
      send: 'Saada', email: 'E-post', password: 'Parool', login: 'Logi sisse',
      register: 'Registreerimine', createAccount: 'Loo konto', noAccount: 'Pole kontot?',
      hasAccount: 'Konto on juba olemas?', signup: 'Registreeru', edit: 'muuda', logout: 'logi välja',
      profile: 'Profiil:', noMessages: 'Sõnumeid veel pole.', noChats: 'Vestlusi veel pole.',
      loginForChats: 'Kontoga vestluste haldamiseks logi sisse.',
      enterMessage: 'Sisesta sõnum', fillNameEmail: 'Sisesta nimi ja e-post',
      fillEmailPassword: 'Sisesta e-post ja parool', invalidCredentials: 'Vale andmed',
      fillAllFields: 'Täida kõik väljad', registerError: 'Registreerimine ebaõnnestus',
      sendError: 'Saatmine ebaõnnestus', connectError: 'Ühenduse viga',
      support: 'Tugi', you: 'Sina'
    }
  };

  if(!i18n[langKey]){ langKey = 'ru'; }
  var L = i18n[langKey];

  var visitorKey = 'support_chat_external_visitor_' + siteKey;
  var profileKey = 'support_chat_profile_' + siteKey;
  var authKey = 'support_chat_auth_' + siteKey;

  var visitorId = localStorage.getItem(visitorKey);
  if(!visitorId){
    visitorId = 'v_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem(visitorKey, visitorId);
  }

  var authToken = localStorage.getItem(authKey) || '';
  var profile = null;
  var activeTicketId = 0;
  var forceNewTicket = false;
  var composingNewTicket = false;
  var lastSig = '';
  var authMode = 'login';

  function escapeHtml(s){ return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function loadProfile(){
    try {
      var raw = localStorage.getItem(profileKey);
      if(!raw){ return null; }
      var p = JSON.parse(raw);
      if(!p || !p.name || !p.email){ return null; }
      return p;
    } catch(e){ return null; }
  }

  function saveProfile(p){
    profile = {name: p.name, email: p.email};
    localStorage.setItem(profileKey, JSON.stringify(profile));
  }

  function clearProfile(){
    profile = null;
    try { localStorage.removeItem(profileKey); } catch(e){}
  }

  var style = document.createElement('style');
  style.textContent = ''+
  '.scx-launcher{position:fixed;right:18px;bottom:18px;z-index:999998;background:#04062b;color:#fff;border:none;border-radius:999px;padding:12px 16px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 12px 24px rgba(4,6,43,.28)}'+
  '.scx-teaser{position:fixed;right:18px;bottom:74px;z-index:999998;max-width:300px;background:#fff;color:#101322;border:1px solid #d9dce7;border-radius:14px;padding:12px 14px;box-shadow:0 14px 28px rgba(10,25,70,.18);display:none}'+
  '.scx-shell{position:fixed;right:14px;bottom:14px;z-index:999999;width:min(393px,calc(100vw - 16px));height:min(68vh,920px);display:none;overflow:visible}'+
  '.scx-panel{position:absolute;left:0;right:0;bottom:0;top:0;background:#fff;border:1px solid #d8dbe6;border-radius:24px;box-shadow:0 20px 44px rgba(7,20,60,.28);overflow:hidden}'+
  '.scx-close{position:absolute;top:-16px;left:-16px;width:40px;height:40px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #d8dbe6;box-shadow:none;color:#787c8e;font-size:28px;cursor:pointer;z-index:4}'+
  '.scx-view{display:none;height:100%;background:#f2f2f5}.scx-view.active{display:flex;flex-direction:column}'+
  '.scx-header{padding:16px;display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fff}.scx-brand{display:flex;align-items:center;gap:12px}.scx-logo{width:48px;height:48px;border-radius:50%;background:#04062b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px}.scx-title{font-size:22px;line-height:1.05;font-weight:800;color:#0d1226;margin:0}.scx-subtitle{font-size:16px;color:#70758a;margin:0}.scx-create{border:none;background:#04062b;color:#fff;border-radius:14px;padding:10px 14px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap}'+
  '.scx-divider{height:1px;background:#ddd}.scx-profile{padding:10px 16px;color:#676d81;font-size:13px;line-height:1.4;border-bottom:1px solid #e3e4ea}.scx-profile-main{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.scx-profile-label{font-weight:700;color:#666d82}.scx-profile-links{margin-top:6px;display:flex;gap:12px;flex-wrap:wrap}.scx-profile a{color:#33579b;text-decoration:none;font-weight:600}'+
  '.scx-msgs{flex:1;overflow:auto;padding:8px 20px 14px 20px}.scx-msg{margin:0 0 18px}.scx-msg-head{display:flex;align-items:center;gap:8px;color:#6d7285;font-size:12px;margin-bottom:6px}.scx-msg-avatar{width:28px;height:28px;border-radius:50%;background:#e6e7ed;color:#70758a;display:flex;align-items:center;justify-content:center;font-size:14px}.scx-msg-bubble{display:inline-block;max-width:78%;padding:14px 16px;border-radius:18px;font-size:17px;line-height:1.45;background:#dddde3;color:#181b2a}.scx-msg-time{margin-top:6px;color:#6d7285;font-size:12px}.scx-msg.mine{text-align:right}.scx-msg.mine .scx-msg-bubble{background:#04062b;color:#fff}.scx-msg.mine .scx-msg-time{text-align:right}'+
  '.scx-compose{padding:12px 16px;background:#f2f2f5;border-top:1px solid #ddd;display:flex;gap:10px;align-items:center}.scx-input-wrap{flex:1;display:flex;align-items:center;height:54px;background:#dddde3;border-radius:16px;padding:0 18px}.scx-input{width:100%;border:0;outline:0;background:transparent;font:400 18px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1a1f33}.scx-send{border:none;background:#a9acb7;color:#fff;width:56px;height:56px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center}.scx-send.active{background:#04062b}.scx-send svg{width:24px;height:24px;display:block;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}'+
  '.scx-wrap{padding:14px 16px 16px;overflow:auto}.scx-back{border:0;background:transparent;color:#6f7488;font-size:16px;font-weight:600;cursor:pointer;padding:0;margin:2px 0 14px}.scx-auth-top{text-align:center;margin:2px 0 12px}.scx-auth-icon{width:64px;height:64px;border-radius:16px;background:#04062b;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:30px}.scx-auth-title{font-size:28px;font-weight:800;color:#111526;margin:0 0 6px}.scx-auth-sub{font-size:16px;color:#70758a;margin:0}.scx-auth-card{background:#f2f2f5;border:1px solid #d2d4dd;border-radius:18px;padding:14px}.scx-auth-label{display:block;color:#1a1f33;font-size:13px;font-weight:700;margin:0 0 6px}.scx-field{display:block;width:100%;box-sizing:border-box;border:0;outline:0;background:#dddde3;border-radius:14px;padding:10px 12px;font-size:16px;color:#1a1f33;margin:0 0 12px}.scx-auth-submit{display:block;width:100%;border:0;background:#04062b;color:#fff;border-radius:14px;padding:11px 14px;font-size:18px;font-weight:700;cursor:pointer;margin-top:4px}.scx-auth-swap{text-align:center;margin-top:10px;color:#6f7488;font-size:13px}.scx-auth-swap a{color:#111526;font-weight:700;text-decoration:none}.scx-tickets{padding:8px 0}.scx-ticket{border:1px solid #d2d4dd;border-radius:14px;padding:12px 14px;margin-bottom:10px;background:#fff;cursor:pointer}.scx-ticket.active{border-color:#04062b;background:#f2f4ff}'+
  '@media (max-width:600px){.scx-shell{right:8px;bottom:8px;width:min(393px,calc(100vw - 16px));height:min(82svh,780px)}.scx-panel{border-radius:22px}.scx-title{font-size:28px}.scx-subtitle{font-size:16px}.scx-create{font-size:14px;padding:10px 12px}}';
  document.head.appendChild(style);

  var teaser = document.createElement('div');
  teaser.className = 'scx-teaser';
  teaser.innerHTML = '<strong>'+L.title+'</strong>' + L.signInToManage;

  var launcher = document.createElement('button');
  launcher.className = 'scx-launcher';
  launcher.textContent = L.launcher;

  var shell = document.createElement('div');
  shell.className = 'scx-shell';
  shell.innerHTML = ''+
    '<button id="scxClose" class="scx-close">×</button>'+
    '<div class="scx-panel">'+
      '<div id="scxViewChat" class="scx-view active">'+
        '<div class="scx-header">'+
          '<div class="scx-brand"><div class="scx-logo">◌</div><div><p class="scx-title">'+L.title+'</p><p class="scx-subtitle">'+L.online+'</p></div></div>'+
          '<button id="scxCreate" class="scx-create">+ '+L.createChat+'</button>'+
        '</div>'+
        '<div class="scx-divider"></div>'+
        '<div id="scxProfile" class="scx-profile"></div>'+
        '<div id="scxMsgs" class="scx-msgs"></div>'+
        '<div class="scx-compose">'+
          '<div class="scx-input-wrap"><input id="scxInput" class="scx-input" placeholder="'+L.msgPlaceholder+'" /></div>'+
          '<button id="scxSend" class="scx-send"><svg viewBox="0 0 24 24"><path d="M22 2L11 13"></path><path d="M22 2L15 22L11 13L2 9L22 2Z"></path></svg></button>'+
        '</div>'+
      '</div>'+
      '<div id="scxViewTickets" class="scx-view"><div class="scx-wrap"><button id="scxBackTickets" class="scx-back">← '+L.backToChat+'</button><div id="scxTickets" class="scx-tickets"></div></div></div>'+
      '<div id="scxViewAuth" class="scx-view"><div class="scx-wrap"><button id="scxBackAuth" class="scx-back">← '+L.backToChat+'</button><div class="scx-auth-top"><div class="scx-auth-icon">◌</div><h3 id="scxAuthTitle" class="scx-auth-title">'+L.signIn+'</h3><p class="scx-auth-sub">'+L.signInToManage+'</p></div><div class="scx-auth-card"><label class="scx-auth-label">'+L.email+'</label><input id="scxLoginEmail" class="scx-field" placeholder="'+L.email+'" /><label class="scx-auth-label">'+L.password+'</label><input id="scxLoginPassword" type="password" class="scx-field" placeholder="'+L.password+'" /><div id="scxRegisterFields" style="display:none;"><label class="scx-auth-label">'+L.yourName+'</label><input id="scxRegName" class="scx-field" placeholder="'+L.yourName+'" /></div><button id="scxAuthSubmit" class="scx-auth-submit">'+L.login+'</button><p class="scx-auth-swap"><span id="scxSwapLabel">'+L.noAccount+'</span> <a href="#" id="scxAuthSwap">'+L.signup+'</a></p></div></div></div>'+
    '</div>';

  document.body.appendChild(teaser);
  document.body.appendChild(launcher);
  document.body.appendChild(shell);

  var msgs = shell.querySelector('#scxMsgs');
  var ticketsBox = shell.querySelector('#scxTickets');
  var profileBox = shell.querySelector('#scxProfile');
  var inputEl = shell.querySelector('#scxInput');
  var sendEl = shell.querySelector('#scxSend');

  function setView(name){
    shell.querySelectorAll('.scx-view').forEach(function(v){
      v.classList.toggle('active', v.id === name);
    });
  }

  function req(path, data){
    var payload = data || {};
    payload.site_key = siteKey;
    payload.visitor_id = visitorId;
    payload.lang = langKey;
    if(authToken){ payload.auth_token = authToken; }
    return fetch(endpoint + path, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).then(function(r){ return r.json(); });
  }

  function renderProfile(auth){
    if(auth){
      profileBox.innerHTML = '<div class="scx-profile-main"><span class="scx-profile-label">'+L.profile+'</span><span>'+escapeHtml(auth.name)+'</span><span>·</span><span>'+escapeHtml(auth.email)+'</span></div><div class="scx-profile-links"><a href="#" id="scxProfileChats">'+L.myChats+'</a><a href="#" id="scxProfileLogout">'+L.logout+'</a></div>';
    } else if(profile){
      profileBox.innerHTML = '<div class="scx-profile-main"><span class="scx-profile-label">'+L.profile+'</span><span>'+escapeHtml(profile.name)+'</span><span>·</span><span>'+escapeHtml(profile.email)+'</span></div><div class="scx-profile-links"><a href="#" id="scxProfileEdit">'+L.edit+'</a><a href="#" id="scxProfileLogin">'+L.login+'</a><a href="#" id="scxProfileChats">'+L.myChats+'</a></div>';
    } else {
      profileBox.innerHTML = '<div class="scx-profile-main"><span class="scx-profile-label">'+L.profile+'</span><span>—</span></div><div class="scx-profile-links"><a href="#" id="scxProfileLogin">'+L.login+'</a><a href="#" id="scxProfileChats">'+L.myChats+'</a></div>';
    }

    var pChats = shell.querySelector('#scxProfileChats');
    var pLogin = shell.querySelector('#scxProfileLogin');
    var pEdit = shell.querySelector('#scxProfileEdit');
    var pLogout = shell.querySelector('#scxProfileLogout');
    if(pChats){ pChats.onclick = function(e){ e.preventDefault(); setView('scxViewTickets'); fetchTickets(); }; }
    if(pLogin){ pLogin.onclick = function(e){ e.preventDefault(); setAuthMode('login'); setView('scxViewAuth'); }; }
    if(pEdit){ pEdit.onclick = function(e){ e.preventDefault(); clearProfile(); renderProfile(null); }; }
    if(pLogout){ pLogout.onclick = function(e){ e.preventDefault(); authToken=''; localStorage.removeItem(authKey); clearProfile(); setView('scxViewChat'); state(); }; }
  }

  function renderMessages(items){
    var html = '';
    (items || []).forEach(function(m){
      var mine = m.sender_type !== 'admin';
      var t = m.created_at && m.created_at.length >= 16 ? m.created_at.substring(11,16) : (m.created_at || '');
      html += '<div class="scx-msg'+(mine?' mine':'')+'">';
      if(!mine){ html += '<div class="scx-msg-head"><span class="scx-msg-avatar">◌</span><span>'+L.support+'</span></div>'; }
      html += '<div class="scx-msg-bubble">'+escapeHtml(m.message)+'</div><div class="scx-msg-time">'+escapeHtml(t)+'</div></div>';
    });
    msgs.innerHTML = html || '<div style="color:#666">'+L.noMessages+'</div>';
    msgs.scrollTop = msgs.scrollHeight;
    lastSig = JSON.stringify(items || []);
  }

  function renderTickets(items){
    if(!authToken){ ticketsBox.innerHTML = '<div style="color:#666">'+L.loginForChats+'</div>'; return; }
    var html = '';
    (items || []).forEach(function(t){
      var active = Number(t.id) === Number(activeTicketId) ? ' active' : '';
      html += '<div class="scx-ticket'+active+'" data-ticket-id="'+Number(t.id)+'"><strong>#'+Number(t.id)+' '+escapeHtml(t.subject)+'</strong><div style="font-size:12px;color:#666">'+escapeHtml(t.status)+' · '+escapeHtml(t.last_message_at)+'</div></div>';
    });
    ticketsBox.innerHTML = html || '<div style="color:#666">'+L.noChats+'</div>';
    ticketsBox.querySelectorAll('.scx-ticket').forEach(function(el){
      el.onclick = function(){ activeTicketId = Number(el.getAttribute('data-ticket-id')) || 0; setView('scxViewChat'); state(); };
    });
  }

  function state(){
    return req('/external/state', {ticket_id: activeTicketId}).then(function(res){
      if(!res || !res.ok){ return; }
      if(!composingNewTicket){ activeTicketId = res.ticket_id || activeTicketId; }
      if(res.auth){ saveProfile({name:res.auth.name,email:res.auth.email}); }
      renderProfile(res.auth || null);
      var sig = JSON.stringify(res.messages || []);
      if(!composingNewTicket && sig !== lastSig){ renderMessages(res.messages || []); }
      if(res.tickets){ renderTickets(res.tickets); }
    }).catch(function(){});
  }

  function fetchTickets(){
    return req('/external/tickets', {}).then(function(res){
      if(res && res.ok){ renderTickets(res.tickets || []); }
    }).catch(function(){});
  }

  function send(){
    var message = (inputEl.value || '').trim();
    var guestName = profile && profile.name ? profile.name : '';
    var guestEmail = profile && profile.email ? profile.email : '';
    if(!message){ alert(L.enterMessage); return; }
    if(!authToken && (!guestName || !guestEmail)){
      var n = window.prompt(L.yourName, '');
      var e = window.prompt(L.yourEmail, '');
      if(!n || !e){ alert(L.fillNameEmail); return; }
      saveProfile({name:n.trim(), email:e.trim()});
      guestName = n.trim(); guestEmail = e.trim();
      renderProfile(null);
    }

    sendEl.disabled = true;
    sendEl.classList.remove('active');
    req('/external/send', {
      ticket_id: activeTicketId,
      force_new_ticket: forceNewTicket ? 1 : 0,
      guest_name: guestName,
      guest_email: guestEmail,
      message: message,
      page_url: location.href
    }).then(function(res){
      if(res && res.ok){
        inputEl.value = '';
        activeTicketId = res.ticket_id || activeTicketId;
        forceNewTicket = false;
        composingNewTicket = false;
        renderMessages(res.messages || []);
        fetchTickets();
      } else {
        alert(L.sendError);
      }
    }).catch(function(){
      alert(L.connectError);
    }).finally(function(){
      sendEl.disabled = false;
    });
  }

  function setAuthMode(mode){
    authMode = mode === 'register' ? 'register' : 'login';
    var regFields = shell.querySelector('#scxRegisterFields');
    var title = shell.querySelector('#scxAuthTitle');
    var submit = shell.querySelector('#scxAuthSubmit');
    var swap = shell.querySelector('#scxAuthSwap');
    var swapLabel = shell.querySelector('#scxSwapLabel');
    regFields.style.display = authMode === 'register' ? 'block' : 'none';
    title.textContent = authMode === 'register' ? L.register : L.signIn;
    submit.textContent = authMode === 'register' ? L.createAccount : L.login;
    swap.textContent = authMode === 'register' ? L.login : L.signup;
    swapLabel.textContent = authMode === 'register' ? L.hasAccount : L.noAccount;
  }

  function submitAuth(){
    var email = (shell.querySelector('#scxLoginEmail').value || '').trim();
    var password = shell.querySelector('#scxLoginPassword').value || '';
    if(!email || !password){ alert(L.fillEmailPassword); return; }

    if(authMode === 'register'){
      var name = (shell.querySelector('#scxRegName').value || '').trim();
      if(!name){ alert(L.fillAllFields); return; }
      req('/external/register', {name:name,email:email,password:password}).then(function(res){
        if(res && res.ok && res.auth_token){
          authToken = res.auth_token;
          localStorage.setItem(authKey, authToken);
          saveProfile({name:res.client.name,email:res.client.email});
          setView('scxViewTickets');
          state();
          fetchTickets();
        } else {
          alert(L.registerError);
        }
      }).catch(function(){ alert(L.connectError); });
      return;
    }

    req('/external/login', {email:email,password:password}).then(function(res){
      if(res && res.ok && res.auth_token){
        authToken = res.auth_token;
        localStorage.setItem(authKey, authToken);
        saveProfile({name:res.client.name,email:res.client.email});
        setView('scxViewTickets');
        state();
        fetchTickets();
      } else {
        alert(L.invalidCredentials);
      }
    }).catch(function(){ alert(L.connectError); });
  }

  launcher.onclick = function(){ shell.style.display = 'block'; state(); };
  shell.querySelector('#scxClose').onclick = function(){ shell.style.display = 'none'; };
  shell.querySelector('#scxCreate').onclick = function(){ forceNewTicket = true; composingNewTicket = true; activeTicketId = 0; renderMessages([]); inputEl.focus(); setView('scxViewChat'); };
  shell.querySelector('#scxBackTickets').onclick = function(){ setView('scxViewChat'); };
  shell.querySelector('#scxBackAuth').onclick = function(){ setView('scxViewChat'); };
  shell.querySelector('#scxAuthSubmit').onclick = submitAuth;
  shell.querySelector('#scxAuthSwap').onclick = function(e){ e.preventDefault(); setAuthMode(authMode === 'login' ? 'register' : 'login'); };
  sendEl.onclick = send;
  inputEl.oninput = function(){ sendEl.classList.toggle('active', (inputEl.value || '').trim() !== ''); };
  inputEl.onkeydown = function(e){ if(e.key === 'Enter'){ e.preventDefault(); send(); } };

  setInterval(function(){ if(shell.style.display === 'block'){ state(); } }, 5000);

  var initialProfile = loadProfile();
  if(initialProfile){ saveProfile(initialProfile); }
  renderProfile(null);
  setAuthMode('login');
})();
