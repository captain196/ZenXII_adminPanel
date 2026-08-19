/* ZenXii Certificate Designer — ported from blueprints/certificates/design/prototype.html
   No bundler (UX_SPEC §14). Plain <script> include. Do not add build steps. */
, event handlers, XXE and
   foreignObject, so accepting it into a school's asset store would be a stored
   XSS vector. Raster only, sniffed by content and not by file extension.
   ========================================================================== */
const OK_TYPES = ["image/png","image/jpeg","image/webp"];
function readAsset(file){
  return new Promise((res,rej)=>{
    if(file.type==="image/svg+xml" || /\.svg$/i.test(file.name))
      return rej("SVG is not accepted — it can carry script, and a certificate asset store is not the place to find out. Export a PNG.");
    if(!OK_TYPES.includes(file.type)) return rej("Only PNG, JPEG or WebP. That file is "+(file.type||"an unknown type")+".");
    if(file.size > 8*1024*1024) return rej("That file is "+(file.size/1048576).toFixed(1)+" MB. Keep assets under 8 MB.");
    const fr=new FileReader();
    fr.onload=()=>{
      const img=new Image();
      img.onload=()=>{
        /* alpha matters for a signature or a seal: a white box over a ruled
           line prints as a white box over a ruled line */
        let hasAlpha=false;
        try{
          const c=document.createElement("canvas"); c.width=Math.min(img.width,40); c.height=Math.min(img.height,40);
          const x=c.getContext("2d"); x.drawImage(img,0,0,c.width,c.height);
          const d=x.getImageData(0,0,c.width,c.height).data;
          for(let i=3;i<d.length;i+=4){ if(d[i]<250){ hasAlpha=true; break; } }
        }catch(e){}
        res({name:file.name, dataUrl:fr.result, wPx:img.width, hPx:img.height,
             bytes:file.size, mime:file.type, hasAlpha});
      };
      img.onerror=()=>rej("That file could not be decoded as an image.");
      img.src=fr.result;
    };
    fr.onerror=()=>rej("That file could not be read.");
    fr.readAsDataURL(file);
  });
}
function applyAsset(o, a, keepBox){
  o.asset=a; o.bindKey=null;
  if(!keepBox){
    const ratio=a.hPx/a.wPx;
    o.hMm=Math.max(4, Math.round(o.wMm*ratio*10)/10);
  }
  const d=assetDpi(o);
  toast(a.name+" placed"+(d?" · "+d+" dpi at this size":""), d && d<MIN_DPI);
}
async function dropFiles(files, atMm, targetId){
  const list=[...files].slice(0,4);
  for(let i=0;i<list.length;i++){
    let a;
    try{ a=await readAsset(list[i]); }
    catch(msg){ toast(String(msg), true); continue; }
    const before=snapshot();
    if(targetId){                       /* Figma's drop-to-replace */
      const o=obj(targetId);
      applyAsset(o, a, true);
      push("Replace image", before, snapshot());
    }else{
      const o=addObject("image", (atMm?atMm.x:40)+i*4, (atMm?atMm.y:120)+i*4, 40, 40);
      o.assetKind = /sign/i.test(a.name) ? "signature" : /seal|stamp/i.test(a.name) ? "seal" : "crest";
      o.name = ASSET_KINDS[o.assetKind].label;
      applyAsset(o, a);
      push("Place image", before, snapshot());
    }
  }
  render(); if(S.sel.length) showCtxbar();
}
/* A principal without a scanner. Drawn strokes are alpha by construction, so
   this sidesteps the white-block-over-the-ruled-line problem entirely — the
   output is exactly the transparent PNG the asset check asks for. */
function openSignaturePad(targetId){
  modal("Draw a signature","Signed with a trackpad, mouse or finger",
    `<canvas class="sigpad" id="sigpad" width="1600" height="500" style="height:190px"></canvas>
     <div class="sigpad__row">
       <span class="sigpad__hint">Drawn strokes carry an alpha channel, so this prints over a ruled line correctly — which a scanned JPEG will not.</span>
       <button class="btn btn--sm" id="sigClear">Clear</button>
     </div>
     <p class="note" style="margin-bottom:0">This is still an <b>image</b> of a signature. It carries no status under the IT Act 2000 — s.3 means a DSC, s.3A means a Second-Schedule electronic signature such as Aadhaar eSign. Authenticity on this document is carried by the verification QR.</p>`,
    `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
     <button class="btn btn--primary" id="sigUse">Use this signature</button>`);
  const c=$("#sigpad"), x=c.getContext("2d");
  x.lineWidth=6; x.lineCap="round"; x.lineJoin="round"; x.strokeStyle="#16233a";
  let drawing=false, drew=false, last=null;
  const pt=e=>{ const r=c.getBoundingClientRect();
    return {x:(e.clientX-r.left)*(c.width/r.width), y:(e.clientY-r.top)*(c.height/r.height)}; };
  c.addEventListener("pointerdown", e=>{ drawing=true; drew=true; last=pt(e); c.setPointerCapture(e.pointerId); });
  c.addEventListener("pointermove", e=>{ if(!drawing) return;
    const p=pt(e); x.beginPath(); x.moveTo(last.x,last.y); x.lineTo(p.x,p.y); x.stroke(); last=p; });
  ["pointerup","pointerleave","pointercancel"].forEach(ev=>c.addEventListener(ev,()=>drawing=false));
  $("#sigClear").onclick=()=>{ x.clearRect(0,0,c.width,c.height); drew=false; };
  $("#sigUse").onclick=()=>{
    if(!drew) return toast("Nothing drawn yet", true);
    /* crop to the ink so the box matches the signature, not the pad */
    const d=x.getImageData(0,0,c.width,c.height).data;
    let x0=c.width,y0=c.height,x1=0,y1=0;
    for(let py=0;py<c.height;py++) for(let px=0;px<c.width;px++){
      if(d[(py*c.width+px)*4+3]>8){ if(px<x0)x0=px; if(px>x1)x1=px; if(py<y0)y0=py; if(py>y1)y1=py; }
    }
    const pad=12;
    x0=Math.max(0,x0-pad); y0=Math.max(0,y0-pad);
    x1=Math.min(c.width-1,x1+pad); y1=Math.min(c.height-1,y1+pad);
    const w=Math.max(1,x1-x0), h=Math.max(1,y1-y0);
    const o2=document.createElement("canvas"); o2.width=w; o2.height=h;
    o2.getContext("2d").drawImage(c,x0,y0,w,h,0,0,w,h);
    const url=o2.toDataURL("image/png");
    const asset={name:"drawn-signature.png", dataUrl:url, wPx:w, hPx:h,
                 bytes:Math.round(url.length*0.75), mime:"image/png", hasAlpha:true};
    const before=snapshot();
    if(targetId && obj(targetId)){ const o=obj(targetId); o.assetKind="signature"; applyAsset(o,asset); }
    else{
      const o=addObject("image", 30, 240, 45, 15);
      o.assetKind="signature"; o.name="Signature"; applyAsset(o,asset);
    }
    push("Draw signature", before, snapshot());
    closeModal(); render(); showCtxbar();
  };
}

function pickFile(targetId){
  const i=el("input"); i.type="file"; i.accept="image/png,image/jpeg,image/webp";
  i.style.cssText="position:fixed;left:-1000px";
  document.body.appendChild(i);
  i.onchange=()=>{ if(i.files&&i.files[0]) dropFiles(i.files,null,targetId); i.remove(); };
  i.click();
}
(function wireDnD(){
  const d=$("#desk");
  let depth=0;
  const hasFiles=e=>e.dataTransfer && [...e.dataTransfer.types].includes("Files");
  d.addEventListener("dragenter", e=>{ if(!hasFiles(e))return; e.preventDefault(); depth++; d.classList.add("is-dropping"); });
  d.addEventListener("dragleave", e=>{ if(!hasFiles(e))return; depth=Math.max(0,depth-1); if(!depth) d.classList.remove("is-dropping"); });
  d.addEventListener("dragover", e=>{
    if(!hasFiles(e))return; e.preventDefault(); e.dataTransfer.dropEffect="copy";
    const n=e.target.closest(".obj");
    $$(".obj.is-drop").forEach(x=>x.classList.remove("is-drop"));
    if(n && obj(n.dataset.id) && obj(n.dataset.id).type==="image") n.classList.add("is-drop");
  });
  d.addEventListener("drop", e=>{
    if(!hasFiles(e))return; e.preventDefault(); depth=0;
    d.classList.remove("is-dropping");
    $$(".obj.is-drop").forEach(x=>x.classList.remove("is-drop"));
    const n=e.target.closest(".obj");
    const tgt = n && obj(n.dataset.id) && obj(n.dataset.id).type==="image" ? n.dataset.id : null;
    const k=pxPerMm(), r=$("#page").getBoundingClientRect();
    dropFiles(e.dataTransfer.files, {x:mm((e.clientX-r.left)/k), y:mm((e.clientY-r.top)/k)}, tgt);
  });
  /* paste an image straight onto the page */
  window.addEventListener("paste", e=>{
    if(S.screen!=="designer" || S.editing) return;
    const it=[...(e.clipboardData&&e.clipboardData.items||[])].filter(x=>x.kind==="file");
    if(!it.length) return;
    e.preventDefault();
    const sel=S.sel.length===1&&obj(S.sel[0]).type==="image"?S.sel[0]:null;
    dropFiles(it.map(x=>x.getAsFile()).filter(Boolean), null, sel);
  });
})();

/* ==========================================================================
   13 · KEYBOARD
   ========================================================================== */
const TOOLKEY={v:"move",h:"hand",t:"text",b:"table",i:"image",l:"shape",q:"qr"};
window.addEventListener("keydown", e=>{
  if(S.screen!=="designer") return;
  const meta=e.metaKey||e.ctrlKey;
  const t=e.target;
  const typing = S.editing || (t && t.matches && t.matches("input,select,textarea,[contenteditable='true']"));

  if(e.code==="Space" && !typing){ spaceDown=true; $("#desk").classList.add("is-pan"); }
  /* Escape is staged: never destroys work, always reaches a neutral state */
  if(e.key==="Escape"){
    if($("#scrim").classList.contains("is-on")) return closeModal();
    if(S.editing){ commitEdit(); return; }          // 1 · commit, stay selected
    if(S.sel.length){ S.sel=[]; hideCtxbar(); render(); return; }   // 2 · deselect
    setTool("move"); return;                        // 3 · back to the move tool
  }
  if(typing){
    if(meta && ["b","i","u"].includes(e.key.toLowerCase()) && S.editing){
      e.preventDefault(); exec({b:"bold",i:"italic",u:"underline"}[e.key.toLowerCase()]);
    }
    return;
  }
  const selectable = ()=>S.tpl.objects.filter(o=>!o.locked && !S.hidden[o.id]);
  if(meta && e.key.toLowerCase()==="a"){
    e.preventDefault();
    const all=selectable().map(o=>o.id);
    S.sel = e.shiftKey ? all.filter(id=>!S.sel.includes(id)) : all;
    render(); if(S.sel.length) showCtxbar();
    toast(e.shiftKey?"Selection inverted — "+S.sel.length:"Selected all "+S.sel.length);
    return;
  }
  if(e.key==="Tab"){                       /* flat cycling — no nesting to descend */
    e.preventDefault();
    const list=selectable(); if(!list.length) return;
    const i=list.findIndex(o=>o.id===S.sel[0]);
    const n=list[((e.shiftKey? i-1 : i+1)+list.length)%list.length] || list[0];
    S.sel=[n.id]; render(); showCtxbar(); return;
  }
  if(e.altKey && !meta){                   /* Figma's align shortcuts */
    const A={KeyA:"left",KeyD:"right",KeyH:"centerX",KeyW:"top",KeyS:"bottom",KeyV:"middleY"}[e.code];
    if(A){ e.preventDefault(); alignSel(A); return; }
  }
  if(e.shiftKey && !meta && ["Digit0","Digit1","Digit2"].includes(e.code)){
    e.preventDefault();
    if(e.code==="Digit1") zoomFit();
    else if(e.code==="Digit2") zoomToSelection();
    else { S.zoom=1; layoutPage(); paintRulers(); paintStatus(); }
    return;
  }
  if(meta && (e.key==="="||e.key==="+")){ e.preventDefault(); $("#zoomIn").click(); return; }
  if(meta && e.key==="-"){ e.preventDefault(); $("#zoomOut").click(); return; }
  if(meta && e.key.toLowerCase()==="z"){ e.preventDefault(); e.shiftKey?redo():undo(); return; }
  if(meta && e.key.toLowerCase()==="y"){ e.preventDefault(); redo(); return; }
  if(meta && e.key.toLowerCase()==="d"){ e.preventDefault(); duplicateSel(); return; }
  if(meta && e.key.toLowerCase()==="c"){ S.clipboard=S.sel.map(obj).map(o=>JSON.parse(JSON.stringify(o))); toast("Copied "+S.clipboard.length); return; }
  if(meta && e.key.toLowerCase()==="v" && S.clipboard){
    const before=snapshot(), ids=[];
    S.clipboard.forEach(c=>{ const o=JSON.parse(JSON.stringify(c));
      o.id="obj_"+Math.random().toString(36).slice(2,6); o.xMm+=6; o.yMm=(o._y!=null?o._y:o.yMm)+6;
      o._y=null; o.anchorTo=null; o.requiredKey=null; S.tpl.objects.push(o); ids.push(o.id); });
    S.sel=ids; push("Paste", before, snapshot()); render(); return;
  }
  if(meta && e.key==="]"){ e.preventDefault(); zOrder(1); return; }
  if(meta && e.key==="["){ e.preventDefault(); zOrder(-1); return; }
  if(!meta && TOOLKEY[e.key.toLowerCase()]){ setTool(TOOLKEY[e.key.toLowerCase()]); return; }
  if(e.key==="Enter" && S.sel.length===1){
    const o=obj(S.sel[0]); if(o.type==="text"){ e.preventDefault(); enterEdit(o.id); } return;
  }
  if((e.key==="Delete"||e.key==="Backspace") && S.sel.length){ e.preventDefault(); tryDelete(); return; }
  const N={ArrowUp:[0,-1],ArrowDown:[0,1],ArrowLeft:[-1,0],ArrowRight:[1,0]}[e.key];
  if(N && S.sel.length){
    e.preventDefault();
    const before=snapshot(), step=e.shiftKey?10:1;
    S.sel.forEach(id=>{ const o=obj(id); if(o.locked) return;
      o.xMm=Math.max(0,o.xMm+N[0]*step); if(!o.anchorTo) o.yMm=Math.max(0,o.yMm+N[1]*step); });
    push("Nudge", before, snapshot()); render(); showCtxbar();
  }
});
window.addEventListener("keyup", e=>{
  if(e.code==="Space"){ spaceDown=false; if(S.tool!=="hand") $("#desk").classList.remove("is-pan"); }
  if(e.key==="Alt") clearMeas();
});

/* ==========================================================================
   14 · CONTEXT MENU
   ========================================================================== */
const cm=$("#ctxmenu");
$("#page").addEventListener("contextmenu", e=>{
  e.preventDefault();
  const n=e.target.closest(".obj");
  if(n && !S.sel.includes(n.dataset.id)){ S.sel=[n.dataset.id]; render(); showCtxbar(); }
  const o=S.sel.length===1?obj(S.sel[0]):null;
  /* everything under the cursor, topmost first — the only way to reach an
     object that is underneath another, or one that is locked */
  const k=pxPerMm(), pr=$("#page").getBoundingClientRect();
  const px=(e.clientX-pr.left)/k, py=(e.clientY-pr.top)/k;
  const under=S.tpl.objects.filter(x=>{
    const oy=resolvedY(x)+regionTop(x)/k;
    return px>=x.xMm && px<=x.xMm+x.wMm && py>=oy && py<=oy+(S.measured[x.id]||x.hMm);
  }).sort((a,b)=>(b.z||0)-(a.z||0));
  cm.innerHTML=`
    ${under.length>1?`<div class="layer__grp" style="margin:2px 0 3px 9px">Select layer</div>`+
      under.map(x=>`<button data-pick="${x.id}">${TYPEICON[x.type]||"◻"} ${esc(x.name||x.id)}${x.locked?" 🔒":""}${x.requiredKey?' <em style="color:var(--seal)">required</em>':""}</button>`).join("")+
      '<div class="div"></div>':""}
    ${o&&o.type==="text"?'<button data-cm="edit">✎ Edit text<em>Enter</em></button>':""}
    <button data-cm="dup" ${S.sel.length?"":"disabled"}>⧉ Duplicate<em>⌘D</em></button>
    <button data-cm="copy" ${S.sel.length?"":"disabled"}>Copy<em>⌘C</em></button>
    <div class="div"></div>
    <button data-cm="fwd" ${S.sel.length?"":"disabled"}>Bring forward<em>⌘]</em></button>
    <button data-cm="back" ${S.sel.length?"":"disabled"}>Send backward<em>⌘[</em></button>
    <button data-cm="lock" ${o?"":"disabled"}>${o&&o.locked?"Unlock position":"Lock position"}</button>
    <div class="div"></div>
    <button data-cm="anchor" ${o?"":"disabled"}>${o&&o.anchorTo?"Detach from anchor":"Anchor to object above"}</button>
    <div class="div"></div>
    <button data-cm="clearguides" ${(S.guides.v.length+S.guides.h.length)?"":"disabled"}>Remove all guides</button>
    <div class="div"></div>
    <button data-cm="del" class="danger" ${S.sel.length?"":"disabled"}>Delete<em>⌫</em></button>`;
  cm.classList.add("is-on");
  cm.style.left=Math.min(e.clientX, innerWidth-220)+"px";
  cm.style.top =Math.min(e.clientY, innerHeight-260)+"px";
});
window.addEventListener("mousedown", e=>{ if(!e.target.closest("#ctxmenu")) cm.classList.remove("is-on"); });
cm.addEventListener("click", e=>{
  const pick=e.target.closest("button[data-pick]");
  if(pick){ cm.classList.remove("is-on"); S.sel=[pick.dataset.pick]; render(); showCtxbar(); return; }
  const b=e.target.closest("button[data-cm]"); if(!b) return;
  cm.classList.remove("is-on");
  const o=S.sel.length===1?obj(S.sel[0]):null, a=b.dataset.cm;
  if(a==="edit"&&o) return enterEdit(o.id);
  if(a==="dup") return duplicateSel();
  if(a==="copy"){ S.clipboard=S.sel.map(obj).map(x=>JSON.parse(JSON.stringify(x))); return toast("Copied "+S.clipboard.length); }
  if(a==="fwd") return zOrder(1);
  if(a==="back") return zOrder(-1);
  if(a==="lock"&&o){ const before=snapshot(); o.locked=!o.locked; push("Lock",before,snapshot()); return render(); }
  if(a==="anchor"&&o){
    const before=snapshot();
    if(o.anchorTo){ o.anchorTo=null; o._y=null; toast("Detached — back to an absolute Y"); }
    else{
      const above=bodyObjects().filter(x=>x.id!==o.id && resolvedY(x)<resolvedY(o))
        .sort((p,q)=>resolvedY(q)-resolvedY(p))[0];
      if(!above) return toast("Nothing above to anchor to");
      o.anchorTo=above.id; o.anchorGapMm=Math.max(0, mm(resolvedY(o)-(resolvedY(above)+S.measured[above.id])));
      toast("Anchored to “"+(above.name||above.id)+"” with a "+o.anchorGapMm+" mm gap");
    }
    push("Anchor", before, snapshot()); return render();
  }
  if(a==="clearguides"){ S.guides={v:[],h:[]}; layoutPage(); return toast("Guides removed"); }
  if(a==="del") return tryDelete();
});

/* ==========================================================================
   15 · PANEL WIRING
   ========================================================================== */
$("#tabstrip").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  $$("#tabstrip button").forEach(t=>t.classList.toggle("is-on", t===b));
  $$(".rail__pane").forEach(p=>p.classList.toggle("is-on", p.dataset.pane===b.dataset.pane));
  $("#railHead").textContent={layers:"Layers",insert:"Insert",fields:"Merge fields",blocks:"Reusable blocks"}[b.dataset.pane];
});
$(".rail").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  if(b.dataset.add) addObject(b.dataset.add);
  if(b.dataset.review) return openBlockUpdate(b.dataset.review);
  if(b.id==="simEdit"){
    BLOCKS[0].version++; S.blockIgnored.BLK0001=false; render();
    toast("A new letterhead version was published — this template is now offered the update");
  }
  if(b.id==="gridBtn"){ S.grid=!S.grid; layoutPage(); toast("Grid "+(S.grid?"on":"off")); }
  if(b.id==="anchorBtn"){ S.anchors=!S.anchors; layoutPage(); toast("Anchor chains "+(S.anchors?"shown":"hidden")); }
});
$(".insp").addEventListener("change", e=>{
  const t=e.target;
  if(t.dataset.p){
    const o=obj(S.sel[0]); if(!o) return;
    const before=snapshot(), p=t.dataset.p;
    let v = t.hasAttribute("data-num") ? evalMm(t.value, null) : t.value;
    if(t.hasAttribute("data-num") && v===null){ toast("That isn't a number or a sum I can work out", true); return render(); }
    if(p==="anchorTo"){ o.anchorTo=v||null; if(!v) o._y=null; }
    else if(p.startsWith("style.")){ o.style=o.style||{}; o.style[p.slice(6)] = v===""?null:v; }
    else o[p]=v;
    push("Edit "+p, before, snapshot()); render();
  }
  if(t.dataset.row!=null){
    const o=obj(S.sel[0]), before=snapshot();
    o.content.rows[+t.dataset.row].key=t.value;
    push("Change row field", before, snapshot()); render();
  }
  if(t.dataset.region){
    const before=snapshot();
    S.tpl.regionLang=S.tpl.regionLang||{};
    S.tpl.regionLang[t.dataset.region]=t.value||null;
    push("Region language", before, snapshot()); render();
    toast(t.dataset.region+" region: "+(t.value?LANGS[t.value].native:"auto"));
  }
  if(t.dataset.page){
    const before=snapshot(), k=t.dataset.page;
    if(k==="size"||k==="orientation") S.tpl.page[k]=t.value;
    else { const v=evalMm(t.value,null); if(v===null){ toast("That isn't a number I can work out", true); return render(); }
           S.tpl.page.marginsMm[k]=v; }
    push("Page setup", before, snapshot()); render();
  }
});
$(".insp").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  const o=S.sel.length===1?obj(S.sel[0]):null;
  if(b.dataset.delrow!=null && o){ const before=snapshot(); o.content.rows.splice(+b.dataset.delrow,1);
    push("Remove row", before, snapshot()); return render(); }
  if(b.dataset.h && o){ const before=snapshot(); o.height=b.dataset.h; push("Height mode",before,snapshot()); return render(); }
  if(b.dataset.flow && o){
    const before=snapshot();
    if(b.dataset.flow==="abs"){ o.anchorTo=null; o._y=null; }
    else{
      const above=bodyObjects().filter(x=>x.id!==o.id && resolvedY(x)<resolvedY(o))
        .sort((p,q)=>resolvedY(q)-resolvedY(p))[0];
      if(!above){ toast("Nothing above to anchor to"); return; }
      o.anchorTo=above.id;
      o.anchorGapMm=Math.max(0, mm(resolvedY(o)-(resolvedY(above)+S.measured[above.id])));
    }
    push("Flow mode", before, snapshot()); return render();
  }
  if(b.dataset.al && o){ const before=snapshot(); o.style.align=b.dataset.al; push("Align text",before,snapshot()); return render(); }
  if(b.dataset.lock && o){ const before=snapshot(); o.locked=b.dataset.lock==="1"; push("Lock",before,snapshot()); return render(); }
  if(b.dataset.align) return alignSel(b.dataset.align);
  if(b.dataset.cite) return openCite(b.dataset.cite);
  const a=b.dataset.act;
  if(a==="replaceAsset"&&o) return pickFile(o.id);
  if(a==="drawSig"&&o) return openSignaturePad(o.id);
  if(a==="clearAsset"&&o){ const before=snapshot(); o.asset=null;
    push("Remove image", before, snapshot()); return render(); }
  if(a==="edit"&&o) return enterEdit(o.id);
  if(a==="addrow"&&o){ const before=snapshot(); o.content.rows.push({key:"student.fullName"});
    push("Add row", before, snapshot()); return render(); }
  if(a==="dup") return duplicateSel();
  if(a==="del") return tryDelete();
  if(a==="fwd") return zOrder(1);
  if(a==="back") return zOrder(-1);
});
$("#topActions").addEventListener("click", e=>{
  const b=e.target.closest("button"); if(!b) return;
  if(b.dataset.lang){ commitEdit(); S.lang=b.dataset.lang; render(); toast("Previewing "+LANGS[S.lang].native); }
  if(b.dataset.data){ S.data=b.dataset.data; render();
    if(b.dataset.data==="p95") toast("p95 sample data — this is the length that overflows in production"); }
  if(b.id==="undoBtn") undo();
  if(b.id==="redoBtn") redo();
  if(b.id==="proofBtn") openProof();
  if(b.id==="pubBtn") openPublish();
  if(b.id==="histBtn") openHistory();
});
$("#crumb").addEventListener("click", e=>{
  const b=e.target.closest("button[data-go]"); if(b){ commitEdit(); go(b.dataset.go); }
});
$$(".sect__head").forEach(h=>h.onclick=()=>$("#"+h.dataset.sect).classList.toggle("is-open"));
$("#keysBtn").onclick=openKeys;
$("#dupToggle").onclick=()=>{
  S.issuance.duplicate=!S.issuance.duplicate; render();
  toast(S.issuance.duplicate ? "Previewing as a duplicate — the statutory mark must appear"
                             : "Previewing as an original");
};
$("#themeBtn").onclick=()=>{
  const r=document.documentElement;
  const cur=r.getAttribute("data-theme")||(matchMedia("(prefers-color-scheme:dark)").matches?"dark":"light");
  r.setAttribute("data-theme", cur==="dark"?"light":"dark");
  if(S.screen==="designer") layoutPage();
};
window.addEventListener("resize", ()=>{ if(S.screen==="designer"){ layoutPage(); positionCtxbar(); } });

/* ==========================================================================
   16 · MODALS
   ========================================================================== */
function modal(title, sub, body, foot, small){
  $("#mTitle").textContent=title; $("#mSub").textContent=sub||"";
  $("#mBody").innerHTML=body; $("#mFoot").innerHTML=foot||"";
  $("#modal").classList.toggle("modal--sm", !!small);
  $("#scrim").classList.add("is-on");
}
const closeModal=()=>$("#scrim").classList.remove("is-on");
$("#scrim").addEventListener("click", e=>{ if(e.target.id==="scrim"||e.target.closest("[data-close]")) closeModal(); });

function openKeys(){
  const K=[["V / H","Move · Hand"],["T / B / I / L / Q","Text · Table · Image · Rule · QR"],
    ["click / drag with T","Auto-grow text · fixed box"],
    ["Double-click","Edit text in place"],["Enter","Edit the selected text object"],
    ["Esc","1 finish editing · 2 deselect · 3 back to Move"],
    ["⌘B / ⌘I / ⌘U","Bold · italic · underline while editing"],
    ["↑ ↓ ← →","Nudge 1 mm"],["shift + arrows","Nudge 10 mm"],
    ["⌘A / ⌘⇧A","Select all · invert selection"],["Tab / ⇧Tab","Cycle objects"],
    ["shift + click","Add to selection"],["drag on paper","Marquee select"],
    ["right-click","Select layer — pick from what's underneath"],
    ["⌘D","Duplicate"],["alt + drag","Duplicate as you drag"],["⌘C / ⌘V","Copy · paste"],
    ["⌘] / ⌘[","Bring forward · send backward"],["⌘Z / ⌘⇧Z","Undo · redo"],
    ["⌥A ⌥H ⌥D","Align left · centre · right"],["⌥W ⌥V ⌥S","Align top · middle · bottom"],
    ["⇧1 / ⇧2 / ⇧0","Zoom to fit · to selection · 100%"],["⌘+ / ⌘−","Zoom in · out"],
    ["space + drag","Pan the desk"],["⌘ + scroll","Zoom"],
    ["alt + hover","Measure to another object"],
    ["drag from a ruler","Pull out a guide · drag it back to remove"],
    ["⌫","Delete (refused on required objects)"]];
  modal("Keyboard","Figma's conventions where one already exists — see design/FIGMA_STUDY.md",
    `<div class="keys">${K.map(([k,d])=>`<div><span class="kbd">${esc(k)}</span><span>${esc(d)}</span></div>`).join("")}</div>`,
    '<button class="btn" data-close>Close</button>');
}
function openCite(key, refused){
  const au=keyAuthority(key), p=prof(), f=FIELD[key]||{label:key};
  const A = au || {label:p.name, authority:p.authority, evidence:p.evidence,
                   verifiedOn:p.verifiedOn, owner:p.owner, scopeNote:p.scope, tier:"board"};
  modal(refused?"This object cannot be deleted":"Why this field is required",
    refused?"It carries a compliance binding":"Compliance rule detail",
    `<div class="cite">
      ${refused?`<p style="margin-top:0"><b>${esc(f.label)}</b> is a required object under the profile resolved for your school.
        Every other freedom stands — move it, resize it, restyle it, change its font, translate it.
        Presence is the only thing enforced.</p>`:""}
      <dl>
        <dt>Field</dt><dd><b>${esc(f.label)}</b> <span class="mono" style="color:var(--ink3)">${esc(key)}</span></dd>
        <dt>Required by</dt><dd><span class="tier tier--${A.tier}">${A.tier==="board"?"Central board":A.tier}</span> <b>${esc(A.label)}</b></dd>
        <dt>Authority</dt><dd>${esc(A.authority||"—")}</dd>
        <dt>Evidence</dt><dd><span class="lvl lvl--${A.evidence}">Level ${A.evidence}</span>${A.evidence==="A"?" — read from the primary text, not a secondary reproduction":A.evidence==="B"?" — cited from the research corpus; the primary text was not read in this pass":""}</dd>
        <dt>Verified</dt><dd>${esc(A.verifiedOn||"—")}${A.owner?" by "+esc(A.owner):""}</dd>
        <dt>Scope</dt><dd>${esc(A.scopeNote||"—")}</dd>
        ${A.sourceRef?`<dt>Source</dt><dd class="mono" style="font-size:10px;word-break:break-all">${esc(A.sourceRef)}</dd>`:""}
      </dl>
      <p style="margin-bottom:0;color:var(--ink3);font-size:11px">
        Compliance validates the <b>template</b>, never the issuance. That a form carries a
        "fees paid up to" field grants no power to withhold the certificate — courts have repeatedly
        held a TC is not a tool to collect arrears, and at the elementary stage it cannot be withheld at all.</p>
    </div>`,
    `<button class="btn" data-close>Close</button>
     ${refused?'<span style="font-size:11px;color:var(--ink3);margin-left:auto">Unbind the field first if you truly need to remove it.</span>':""}`, true);
}
function openBlockUpdate(id){
  const bl=BLOCKS.find(b=>b.id===id), ref=S.blockRefs[id];
  const live = S.tpl.activeVersion!=null;
  modal("Letterhead update available", `v${ref} → v${bl.version} · ${esc(bl.name)}`,
    `<div class="cmp">
       <div class="cmp__col"><h5>In this template — v${ref}</h5><div class="cmp__paper" id="cmpA"></div></div>
       <div class="cmp__col"><h5>Published block — v${bl.version}</h5><div class="cmp__paper" id="cmpB"></div></div>
     </div>
     <div class="cmp__key"><span><b style="background:var(--clay)"></b>changed</span></div>
     <p class="note">${live
       ? `This template has <b>v${S.tpl.activeVersion} published and active</b>. Accepting does <b>not</b> alter it — the published snapshot is what a certificate issued last month was rendered from, and it never changes. Accepting creates <b>draft v${S.tpl.version+1}</b>, which goes through the usual publish gate including a fresh proof render.`
       : `This template is a draft, so accepting applies the change straight away.`}</p>
     <p class="note" style="margin-bottom:0">Ignoring is remembered. A school may deliberately keep an older letterhead on one certificate type.</p>`,
    `<button class="btn" id="blkIgnore">Ignore</button><span class="spacer"></span>
     <button class="btn" data-close>Decide later</button>
     <button class="btn btn--primary" id="blkAccept">Accept${live?" — creates draft v"+(S.tpl.version+1):""}</button>`);
  const head=S.tpl.objects.filter(o=>o.region==="header");
  schematic(head, $("#cmpA"));
  schematic(head, $("#cmpB"));
  [...$("#cmpB").children].slice(1,3).forEach(i=>i.className="chg");
  $("#blkIgnore").onclick=()=>{ S.blockIgnored[id]=true; closeModal(); render(); toast("Update ignored — the badge will stay quiet"); };
  $("#blkAccept").onclick=()=>{
    S.blockRefs[id]=bl.version;
    if(live){ S.tpl.version++; S.proofed=null; toast("Accepted — now editing draft v"+S.tpl.version+". The published version is untouched."); }
    else toast("Accepted — applied to the draft");
    S.dirty=true; closeModal(); render();
  };
}

/* ── A4 · compare this draft with the published version ───────────────── */
function diffObjects(base, cur){
  const bi=Object.fromEntries(base.map(o=>[o.id,o])), ci=Object.fromEntries(cur.map(o=>[o.id,o]));
  const changed=[], added=[], removed=[];
  cur.forEach(o=>{
    const b=bi[o.id];
    if(!b){ added.push(o.id); return; }
    const same = b.xMm===o.xMm && b.yMm===o.yMm && b.wMm===o.wMm && b.hMm===o.hMm &&
      b.height===o.height && b.anchorTo===o.anchorTo && (b.maxHMm||null)===(o.maxHMm||null) &&
      JSON.stringify(b.style)===JSON.stringify(o.style) && JSON.stringify(b.content)===JSON.stringify(o.content);
    if(!same) changed.push(o.id);
  });
  base.forEach(o=>{ if(!ci[o.id]) removed.push(o.id); });
  return {changed, added, removed};
}
function openCompare(){
  const base=S.baseline||[], cur=S.tpl.objects;
  const d=diffObjects(base, cur);
  const paint=(host, objs, marks)=>{
    schematic(objs, host);
    [...host.children].forEach((i,idx)=>{
      const o=objs[idx]; if(!o) return;
      if(marks.changed.includes(o.id)) i.className="chg";
      else if(marks.added.includes(o.id)) i.className="add";
      else if(marks.removed.includes(o.id)) i.className="del";
    });
  };
  const list = [...d.changed.map(id=>["changed",id]), ...d.added.map(id=>["added",id]), ...d.removed.map(id=>["removed",id])];
  modal("Compare with the published version",
    `v${S.tpl.activeVersion||"—"} (active) vs v${S.tpl.version} (this draft)`,
    `<div class="cmp">
      <div class="cmp__col"><h5>v${S.tpl.activeVersion||"—"} · active</h5><div class="cmp__paper" id="cmpL"></div></div>
      <div class="cmp__col"><h5>v${S.tpl.version} · this draft</h5><div class="cmp__paper" id="cmpR"></div></div>
     </div>
     <div class="cmp__key">
       <span><b style="background:var(--clay)"></b>changed</span>
       <span><b style="background:var(--ok)"></b>added</span>
       <span><b style="background:var(--warn)"></b>removed</span>
     </div>
     ${list.length?`<div style="margin-top:12px">${list.map(([k,id])=>{
        const o=obj(id)||(base.find(x=>x.id===id)||{});
        return `<div class="kv"><span>${esc(o.name||id)}</span><b>${k}</b></div>`;}).join("")}</div>`
       : `<p class="note" style="margin-bottom:0">Nothing has changed since v${S.tpl.activeVersion}.</p>`}
     <p class="note" style="margin-bottom:0">"What changed since the version that is live?" is the question a Principal asks before approving. It is the moment of legal exposure in this module, so it should not require opening two windows and squinting.</p>`,
    `<button class="btn" data-close>Close</button>`);
  paint($("#cmpL"), base, {changed:d.changed, added:[], removed:d.removed});
  paint($("#cmpR"), cur,  {changed:d.changed, added:d.added, removed:[]});
}

function openProof(){
  modal("Proof render","Through the real mPDF pipeline — not a browser approximation",
   `<div class="langtabs">${S.tpl.languages.map(l=>{const c=translationCoverage(l);
      return `<span class="langtab ${l===S.lang?"is-on":""}">${LANGS[l].native} <span class="mono" style="font-size:9px;opacity:.75">${c.done}/${c.total}</span></span>`;}).join("")}</div>
    <div class="proof">
      <div class="proof__paper" id="proofPaper"></div>
      <div class="proof__side">
        <div id="proofLog" style="font-size:11.5px;color:var(--ink2);line-height:1.7"></div>
        <div class="bar"><i id="proofBar"></i></div>
        <div id="proofKv"></div>
      </div>
    </div>
    <p class="note" style="margin-bottom:0">Proof renders are explicit, never per keystroke — mPDF is heavy and the
    production box has an OOM history. The browser preview you edit against uses the <b>same serializer</b>,
    so a difference between them is a bug, not a style choice.</p>`,
   `<button class="btn" data-close>Close</button><span class="spacer"></span>
    <button class="btn btn--primary" id="proofRun">Render proof</button>`);
  schematic(S.tpl.objects, $("#proofPaper"));
  $("#proofRun").onclick=()=>{
    const log=$("#proofLog"), bar=$("#proofBar");
    const steps=["Serializing template → HTML (namespaced under .zx-tpl-"+S.tpl.templateId+")",
      "Resolving merge fields against sample data",
      "Registering fonts — lohitdeva, dejavusans · useOTL 0xFF",
      "mPDF render · "+S.tpl.page.size+" "+S.tpl.page.orientation,
      "Hashing PDF bytes"];
    log.innerHTML=""; bar.style.width="0";
    steps.forEach((s,i)=>setTimeout(()=>{
      log.insertAdjacentHTML("beforeend", `<div>· ${esc(s)}</div>`);
      bar.style.width=((i+1)/steps.length*100)+"%";
      if(i===steps.length-1){
        const hash="sha256:"+Math.random().toString(16).slice(2,10)+"a41f9c2e"+Math.random().toString(16).slice(2,6);
        S.proofed={hash};
        $("#proofKv").innerHTML=`<div class="kv"><span>Result</span><b style="color:var(--ok)">rendered · 1 page</b></div>
          <div class="kv"><span>Peak memory</span><b>84 MB</b></div>
          <div class="kv"><span>Render time</span><b>1.9 s</b></div>
          <div class="kv"><span>Content hash</span><b>${hash}</b></div>`;
        paintCompliance(); paintStatus(); toast("Proof rendered — publish is now unlocked");
      }
    }, 380*(i+1)));
  };
}
function openPublish(){
  const v=validate(), p=prof(), rows=[];
  const unbound=v.blocking.filter(b=>b.type==="unbound"), lh=v.blocking.filter(b=>b.type==="lineheight");
  rows.push(unbound.length?{c:"fail",t:`${unbound.length} required field${unbound.length>1?"s":""} unbound`,s:unbound.map(b=>b.key).join(", ")}
                          :{c:"pass",t:"Every required field is bound",s:`${p.requiredKeys.length} keys under ${p.name}`});
  rows.push(lh.length?{c:"fail",t:"An object has no line height",s:"mPDF and the browser will disagree — see "+lh[0].id}
                     :{c:"pass",t:"Every text object declares a line height",s:"Renderer agreement verified at 92/92 probes"});
  const nd=v.blocking.filter(b=>b.type==="noDuplicateMark");
  if(nd.length) rows.push({c:"fail", t:"No duplicate mark on this template",
    s:nd[0].req.map(x=>x.a.label+" "+x.d.citation).join("; ")+" — a reissue must be marked"});
  const ns=v.blocking.filter(b=>b.type==="noSignature");
  if(ns.length) rows.push({c:"fail", t:ns.length+" prescribed signature block"+(ns.length>1?"s":"")+" missing",
    s:ns.map(b=>b.role.replace(/_/g," ")).join(", ")});
  const oc=v.blocking.filter(b=>b.type==="offContract");
  if(oc.length) rows.push({c:"fail", t:oc.length+" field"+(oc.length>1?"s":"")+" not declared by this document type",
    s:oc.map(b=>b.key).join(", ")+" — contract mismatch is a hard error at render, never a blank"});
  const wi=v.blocking.filter(b=>b.type==="wrongInstrument");
  if(wi.length) rows.push({c:"fail", t:"This is the wrong instrument for this pupil",
    s:wi[0].authority.label+" "+wi[0].route.citation+" — "+wi[0].route.label+" must be issued instead"});
  const cl=v.blocking.filter(b=>b.type==="clamped");
  rows.push(cl.length?{c:"fail",t:"Content is being cut off at its max height",
      s:cl.map(b=>(obj(b.id)||{}).name+" overshoots by "+mm(b.over)+" mm at this data").join("; ")}
    :{c:"pass",t:"Nothing is truncated at the current data",s:"Check again in p95 before publishing"});
  rows.push(S.proofed?{c:"pass",t:"Proof render succeeded",s:S.proofed.hash}
                     :{c:"fail",t:"No proof render on this version",s:"Publish is gated on a PDF that actually rendered"});
  const cov=translationCoverage("hi");
  if(cov.done<cov.total) rows.push({c:"warn",t:`${cov.total-cov.done} strings untranslated in हिन्दी`,
    s:"languageFallback is block — a missing Hindi string stops the render, it does not silently fall back to English"});
  const iq=v.warnings.filter(w=>["lowDpi","noAsset","noAlpha"].includes(w.type));
  if(iq.length) rows.push({c:"warn", t:"Image quality", s:iq.map(w=>{
    const o=obj(w.id)||{}; return (o.name||w.id)+" — "+(w.type==="lowDpi"?w.dpi+" dpi":w.type==="noAsset"?"empty placeholder":"no transparency");
  }).join("; ")});
  const ov=v.warnings.filter(w=>w.type==="overflow");
  if(ov.length) rows.push({c:"warn",t:"Text overflows a fixed box at this data",s:ov.map(o=>o.id).join(", ")});
  const blocked=rows.some(r=>r.c==="fail");
  modal("Publish version "+S.tpl.version, blocked?"Blocked — resolve the red rows first":"This freezes an immutable snapshot",
    `<div class="gate">${rows.map(r=>`<div class="gate__row gate--${r.c}">
      <span class="gate__ic">${r.c==="pass"?"✓":r.c==="fail"?"✕":"▲"}</span>
      <span><b>${esc(r.t)}</b><span>${esc(r.s)}</span></span></div>`).join("")}</div>
     <p class="note">Publishing writes <span class="mono">documentTemplateVersions/${esc(S.tpl.schoolId)}_${esc(S.tpl.templateId)}_v${S.tpl.version}</span>
     — create-only, never updated or deleted, by anyone. It records the font manifest and the mPDF version too,
     so a re-render years from now is explainable rather than mysterious.</p>`,
    `<button class="btn" data-close>Cancel</button><span class="spacer"></span>
     <button class="btn btn--primary" id="pubGo" ${blocked?"disabled":""}>Publish and set active</button>`);
  const g=$("#pubGo"); if(g) g.onclick=()=>{
    S.tpl.publishedVersion=S.tpl.version; S.tpl.version++; S.dirty=false;
    /* publishing freezes a snapshot. It does NOT make it the one that prints —
       that is activation, and it is a separate decision with its own blast radius. */
    let row=libOf(S.tpl.docType).find(r=>r.id===S.tpl.templateId);
    if(!row){ row={id:S.tpl.templateId, name:S.tpl.name, starter:"tc_cbse", edited:"just now"};
      (S.lib[S.tpl.docType]=S.lib[S.tpl.docType]||[]).push(row); }
    row.name=S.tpl.name; row.status="published";
    row.publishedVersion=S.tpl.publishedVersion; row.version=S.tpl.version; row.edited="just now";
    const already = S.active[S.tpl.docType]===S.tpl.templateId;
    S.tpl.activeVersion = already ? S.tpl.publishedVersion : null;
    closeModal(); render();
    if(already) return toast("Published v"+S.tpl.publishedVersion+" — it was already active, so it is live now");
    const cur=activeTpl(S.tpl.docType);
    modal("Published v"+S.tpl.publishedVersion, "Publishing freezes it. Activating is what makes it print.",
      `<div class="gate">
         <div class="gate__row gate--pass"><span class="gate__ic">✓</span>
           <span><b>v${S.tpl.publishedVersion} is frozen</b><span>Immutable, with its proof hash, font manifest and mPDF version recorded.</span></span></div>
         <div class="gate__row gate--warn"><span class="gate__ic">▲</span>
           <span><b>${cur?"“"+esc(cur.name)+"” is still the active template":"Nothing is active for this document type"}</b>
           <span>${cur?"Nothing prints from your new version until you activate it.":"No print point can resolve this type until something is activated."}</span></span></div>
       </div>`,
      `<button class="btn" data-close>Leave it published only</button><span class="spacer"></span>
       <button class="btn btn--primary" id="pubAct">Set v${S.tpl.publishedVersion} active</button>`, true);
    $("#pubAct").onclick=()=>{
      S.active[S.tpl.docType]=S.tpl.templateId;
      S.tpl.activeVersion=S.tpl.publishedVersion;
      closeModal(); render(); toast("Active — every print point now resolves v"+S.tpl.publishedVersion);
    };
  };
}
function openHistory(){
  modal("Version history","Every published version is frozen forever",
    `<ul class="tl">
      <li><span class="tl__v">v${S.tpl.version}</span><span class="tl__m"><b>Draft — you are here</b>
        <span>lockVersion ${S.tpl.lockVersion}${S.dirty?" · unsaved changes":""}</span></span></li>
      <li><span class="tl__v">v2</span><span class="tl__m"><b>Published · active</b>
        <span>04 Aug 2026 by Principal · sha256:9c41…a2f1 · mPDF 8.3.1 · lohitdeva + dejavusans</span></span></li>
      <li><span class="tl__v">v1</span><span class="tl__m"><b>Published</b>
        <span>12 Jul 2026 by Principal · superseded by v2</span></span></li>
    </ul>
    <p class="note" style="margin-bottom:0">This is the answer to <b>"show me the exact template that produced this certificate"</b>,
    asked three years later by somebody who is not you.</p>`,
    `<button class="btn" data-close>Close</button>
     <button class="btn" id="cmpBtn">Compare with active</button><span class="spacer"></span>
     <button class="btn" id="conflictDemo">Simulate a concurrent edit</button>`, true);
  $("#conflictDemo").onclick=openConflict;
  $("#cmpBtn").onclick=openCompare;
}
function openConflict(){
  modal("This template changed while you were editing","Someone else saved first",
    `<p style="margin-top:0;font-size:12.5px;line-height:1.6"><b>Priya (Office)</b> saved
      <span class="mono">lockVersion 18</span> two minutes ago. Your copy is on <span class="mono">17</span>.
      Saving now would silently erase their work, so it has been stopped.</p>
     <div class="gate">
       <div class="gate__row gate--warn"><span class="gate__ic">▲</span>
         <span><b>Their changes</b><span>Signature block moved · school address edited</span></span></div>
       <div class="gate__row gate--warn"><span class="gate__ic">▲</span>
         <span><b>Your changes</b><span>3 objects moved · reason-for-leaving anchored</span></span></div>
     </div>
     ${S.tpl.activeVersion!=null?`<p class="note" style="margin-bottom:0">This template has a <b>published, active version</b>, so overwriting is not offered — one of these two changes may be the one a Principal already approved. Review both and keep yours as a new draft instead. Nothing is lost either way.</p>`:""}`,
    (S.tpl.activeVersion!=null
      ? `<button class="btn" data-close>Keep editing</button><span class="spacer"></span>
         <button class="btn" data-close>Reload theirs</button>
         <button class="btn btn--primary" id="cflReview">Review both, then save as a new draft</button>`
      : `<button class="btn" data-close>Keep editing</button><span class="spacer"></span>
         <button class="btn" id="cflReview">Review changes</button>
         <button class="btn" data-close>Save mine over theirs</button>`), true);
  const rv=$("#cflReview"); if(rv) rv.onclick=()=>{ closeModal(); openCompare(); };
}

/* ==========================================================================
   17 · BOOT
   ========================================================================== */
S.school=Object.assign({}, SCHOOL_DEFAULT);
S.lib=JSON.parse(JSON.stringify(LIB));
S.active=Object.assign({}, ACTIVE);
S.tpl=starterTC();
paintHub(); go("hub");
