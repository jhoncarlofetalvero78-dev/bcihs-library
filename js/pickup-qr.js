'use strict';
let pickupListVersion = 0, pickupListSnapshot = '';
async function loadPickupCodes() {
  const version = ++pickupListVersion;
  const holder = document.getElementById('pickupCodes');
  try {
    const response = await fetch('backend/my-pickup-requests-data.php',{credentials:'same-origin',cache:'no-store'});
    const data = await response.json();
    if (version !== pickupListVersion) return;
    if (!response.ok) throw new Error(data.error || 'Could not load pickup requests.');
    // Preserve an already displayed QR when the eligible requests are unchanged.
    const snapshot = JSON.stringify([data.requests,data.csrfToken]);
    if (snapshot === pickupListSnapshot) return;
    pickupListSnapshot = snapshot;
    holder.replaceChildren();
    if (!data.requests.length) { holder.textContent = 'No approved requests awaiting pickup. Your borrowing history remains below.'; return; }
    data.requests.forEach(request => {
      const card = document.createElement('article'); card.className = 'pickup-qr-card';
      const title = document.createElement('h3'); title.textContent = request.title;
      const instructions = document.createElement('p'); instructions.textContent = 'Show this QR Code to the librarian when claiming your book. Approval holds a copy; it is not yet checked out.';
      const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Show pickup QR';
      const message = document.createElement('p'); message.setAttribute('role','status');
      const output = document.createElement('div');
      button.addEventListener('click',async () => {
        button.disabled = true; output.replaceChildren(); message.textContent = 'Checking your request…';
        try {
          const result = await fetch('backend/pickup-qr-data.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrfToken},body:JSON.stringify({id:request.id})});
          const qr = await result.json(); if (!result.ok) throw new Error(qr.error || 'Could not show the pickup QR.');
          const code = qrcodegen.QrCode.encodeText(qr.url,qrcodegen.QrCode.Ecc.MEDIUM);
          const border = 4, scale = 5, canvas = document.createElement('canvas');
          canvas.width = canvas.height = (code.size + border * 2) * scale;
          canvas.setAttribute('role','img'); canvas.setAttribute('aria-label','Pickup QR for ' + request.title);
          const context = canvas.getContext('2d'); context.fillStyle = '#fff'; context.fillRect(0,0,canvas.width,canvas.height); context.fillStyle = '#000';
          for (let y=0;y<code.size;y++) for (let x=0;x<code.size;x++) if(code.getModule(x,y)) context.fillRect((x+border)*scale,(y+border)*scale,scale,scale);
          const link = document.createElement('a'); link.href = qr.url; link.textContent = 'Open pickup verification'; link.referrerPolicy = 'no-referrer';
          output.append(canvas,link); message.textContent = 'Only library staff can verify this code and confirm checkout. Refresh this page after pickup; a used QR cannot check out another copy.';
          if (['localhost','127.0.0.1','[::1]'].includes(new URL(qr.url).hostname)) message.textContent += ' This URL is local to this computer; ask staff to configure a LAN or HTTPS address for cellphone use.';
        } catch (error) { message.textContent = error.message || 'Could not generate the QR. Reload to retry; manual checkout remains available.'; }
        finally { button.disabled = false; }
      });
      card.append(title,instructions,button,message,output); holder.appendChild(card);
    });
  } catch (error) {
    if (version !== pickupListVersion) return;
    pickupListSnapshot = ''; holder.replaceChildren();
    holder.textContent = error.message || 'Could not load pickup requests. Reload to retry.';
  }
}
loadPickupCodes();
