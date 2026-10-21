/* global wp */
// Scripts of the admin screens of MFPro Anonymous Digital Sales. Each block only runs on the screen
// whose elements it finds. Values from PHP (AJAX address, router address, nonce) come from
// wp_localize_script as window.AnonymousSalesAdmin (see anonymous_sales_admin_enqueue_assets).

// Offers screen: price preview, approved-crypto check, media pickers, password toggle
(function(){
var routerUrl=(window.AnonymousSalesAdmin&&window.AnonymousSalesAdmin.routerUrl)||"";
var input=document.getElementById("anonymous_sales_offer_price_cents");
var preview=document.getElementById("anonymous_sales_offer_price_preview");
if(input&&preview){var update=function(){var cents=parseInt(input.value||"0",10);if(isNaN(cents)||cents<0){cents=0;}preview.textContent="$"+(cents/100).toFixed(2);};input.addEventListener("input",update);update();}
var checkBtn=document.getElementById("anonymous_sales_offer_check_approved_crypto");
var checkResult=document.getElementById("anonymous_sales_offer_check_approved_crypto_result");
if(checkBtn&&checkResult&&input&&routerUrl){checkBtn.addEventListener("click",function(){var cents=parseInt(input.value||"0",10);if(isNaN(cents)||cents<=0){checkResult.textContent="Set Price (cents) greater than 0 first.";return;}checkBtn.disabled=true;checkResult.textContent="Checking approved crypto...";var params=new URLSearchParams();params.set("action","check_approved_crypto");params.set("include_minimums","1");params.set("price_currency","usd");params.set("price_amount",String(cents/100));fetch(routerUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:params.toString()}).then(function(r){return r.json();}).then(function(data){if(!data||!data.success){checkResult.textContent=(data&&data.message)?data.message:"Could not fetch approved crypto list.";return;}var coins=Array.isArray(data.approved_coins)?data.approved_coins:[];if(!coins.length){checkResult.textContent="No approved crypto returned by NOWPayments.";return;}var eligible=Array.isArray(data.eligible_coins)?data.eligible_coins:[];var ineligible=Array.isArray(data.ineligible_coins)?data.ineligible_coins:[];var text="Approved crypto ("+coins.length+"):\n"+coins.join(", ")+"\n\nEligible for this price ("+eligible.length+"):\n"+(eligible.length?eligible.join(", "):"none");if(ineligible.length){text+="\n\nBelow minimum ("+ineligible.length+"):\n"+ineligible.join(", ");}checkResult.textContent=text;}).catch(function(){checkResult.textContent="Network error while checking approved crypto.";}).finally(function(){checkBtn.disabled=false;});});}
var bindMediaPicker=function(buttonId,inputId,titleText,buttonText){var btn=document.getElementById(buttonId);var urlInput=document.getElementById(inputId);if(!btn||!urlInput){return;}btn.addEventListener("click",function(){if(typeof wp==="undefined"||!wp.media){window.alert("Media Library is not available. Please reload the page.");return;}var frame=wp.media({title:titleText,button:{text:buttonText},multiple:false});frame.on("select",function(){var selection=frame.state().get("selection").first();if(!selection){return;}var attachment=selection.toJSON();if(attachment&&attachment.url){urlInput.value=attachment.url;urlInput.dispatchEvent(new Event("input"));}});frame.open();});};
var bindClear=function(buttonId,inputId){var btn=document.getElementById(buttonId);var urlInput=document.getElementById(inputId);if(!btn||!urlInput){return;}btn.addEventListener("click",function(){urlInput.value="";urlInput.dispatchEvent(new Event("input"));urlInput.focus();});};
var bindImagePreview=function(inputId,imageId){var urlInput=document.getElementById(inputId);var img=document.getElementById(imageId);if(!urlInput||!img){return;}var refresh=function(){var url=(urlInput.value||"").trim();if(!url){img.hidden=true;img.removeAttribute("src");return;}img.src=url;};img.addEventListener("load",function(){img.hidden=false;});img.addEventListener("error",function(){img.hidden=true;});urlInput.addEventListener("input",refresh);refresh();};
bindMediaPicker("anonymous_sales_pick_offer_logo","anonymous_sales_offer_logo_url","Select Offer Logo","Use this image");
bindClear("anonymous_sales_clear_offer_logo","anonymous_sales_offer_logo_url");
bindImagePreview("anonymous_sales_offer_logo_url","anonymous_sales_offer_logo_preview");
bindMediaPicker("anonymous_sales_pick_resource_file","anonymous_sales_resource_address","Select Resource File","Use this file");
bindClear("anonymous_sales_clear_resource_file","anonymous_sales_resource_address");
var resourcePasswordInput=document.getElementById("anonymous_sales_resource_password");
var resourcePasswordToggle=document.getElementById("anonymous_sales_toggle_resource_password");
if(resourcePasswordInput&&resourcePasswordToggle){resourcePasswordToggle.addEventListener("click",function(){var isHidden=resourcePasswordInput.type==="password";resourcePasswordInput.type=isHidden?"text":"password";resourcePasswordToggle.textContent=isHidden?"Hide":"Show";});}
})();

// Orders screen: manual status and delete dialogs
(function(){
var openButtons=document.querySelectorAll(".anonymous-sales-open-manual-status");
openButtons.forEach(function(btn){
btn.addEventListener("click",function(){
var dialogId=btn.getAttribute("data-target-dialog")||"";
if(!dialogId){return;}
var dialog=document.getElementById(dialogId);
if(!dialog||typeof dialog.showModal!=="function"){return;}
dialog.showModal();
});
});
var closeButtons=document.querySelectorAll(".anonymous-sales-close-manual-status");
closeButtons.forEach(function(btn){
btn.addEventListener("click",function(){
var dialog=btn.closest("dialog");
if(dialog){dialog.close();}
});
});
var dialogs=document.querySelectorAll(".anonymous-sales-manual-status-dialog");
dialogs.forEach(function(dialog){
dialog.addEventListener("click",function(event){
var rect=dialog.getBoundingClientRect();
var inside=event.clientX>=rect.left&&event.clientX<=rect.right&&event.clientY>=rect.top&&event.clientY<=rect.bottom;
if(!inside){dialog.close();}
});
});
var forms=document.querySelectorAll(".anonymous-sales-manual-status-form");
if(!forms.length){return;}
forms.forEach(function(form){
var select=form.querySelector("select[name=\"order_currentStatus\"]");
var ack=form.querySelector(".anonymous-sales-manual-status-ack");
var saveBtn=form.querySelector(".anonymous-sales-manual-status-save");
var currentStatus=(form.getAttribute("data-current-status")||"").trim();
var syncSaveState=function(){
if(!select||!ack||!saveBtn){return;}
var targetStatus=(select.value||"").trim();
var changed=targetStatus!==""&&targetStatus!==currentStatus;
saveBtn.disabled=!(changed&&ack.checked);
};
if(select){select.addEventListener("change",syncSaveState);}
if(ack){ack.addEventListener("change",syncSaveState);}
syncSaveState();
form.addEventListener("submit",function(event){
var targetStatus=(select.value||"").trim();
if(!select||!ack||!saveBtn){return;}
if(!targetStatus||targetStatus===currentStatus){return;}
if(!ack.checked){event.preventDefault();window.alert("Before manual status update, check the risk acknowledgment box.");return;}
var message="Manual status change is NOT recommended.\n\nDo this only if payment was received outside NOWPayments, preferably after the NOWPayments payment link has expired.\n\nCurrent status: "+currentStatus+"\nNew status: "+targetStatus+"\n\nDo you want to continue?";
if(!window.confirm(message)){event.preventDefault();return;}
saveBtn.disabled=true;
var dialog=form.closest("dialog");if(dialog){dialog.close();}
});
});
var deleteOpenBtns=document.querySelectorAll(".anonymous-sales-open-delete-order");
deleteOpenBtns.forEach(function(btn){
btn.addEventListener("click",function(){
var dialogId=btn.getAttribute("data-target-dialog")||"";
if(!dialogId){return;}
var dialog=document.getElementById(dialogId);
if(!dialog||typeof dialog.showModal!=="function"){return;}
dialog.showModal();
});
});
var deleteCloseBtns=document.querySelectorAll(".anonymous-sales-close-delete-order");
deleteCloseBtns.forEach(function(btn){
btn.addEventListener("click",function(){
var dialog=btn.closest("dialog");
if(dialog){dialog.close();}
});
});
var deleteDialogs=document.querySelectorAll(".anonymous-sales-delete-order-dialog");
deleteDialogs.forEach(function(dialog){
dialog.addEventListener("click",function(event){
var rect=dialog.getBoundingClientRect();
var inside=event.clientX>=rect.left&&event.clientX<=rect.right&&event.clientY>=rect.top&&event.clientY<=rect.bottom;
if(!inside){dialog.close();}
});
});
})();

// Orders screen: "Check now" button
(function(){
var checkButtons=document.querySelectorAll(".anonymous-sales-check-order-now");
checkButtons.forEach(function(btn){
btn.addEventListener("click",function(){
var orderId=btn.getAttribute("data-order-id")||"";
var nonce=btn.getAttribute("data-nonce")||"";
if(!orderId){return;}
btn.disabled=true;btn.textContent="Checking...";
var body=new FormData();
body.append("action","anonymous_sales_check_order_now");
body.append("order_id",orderId);
body.append("nonce",nonce);
fetch(window.AnonymousSalesAdmin.ajaxUrl,{method:"POST",body:body})
.then(function(r){return r.json();})
.then(function(data){
if(data&&data.success&&data.data&&data.data.new_status){
var row=btn.closest("tr");
if(row){var statusCell=row.querySelectorAll("td")[3];if(statusCell){statusCell.textContent=data.data.new_status;}}
btn.textContent="Done";
}else{btn.textContent="No change";btn.disabled=false;}
})
.catch(function(){btn.textContent="Error";btn.disabled=false;});
});
});
})();

// Settings screen: key rotation warning, approved-crypto check
(function(){
var keyRotationCheckbox=document.getElementById("anonymous_sales_regenerate_router_key");
var approvedCryptoBtn=document.getElementById("anonymous_sales_check_approved_crypto");
var approvedCryptoResult=document.getElementById("anonymous_sales_check_approved_crypto_result");
var approvedCryptoPriceCents=document.getElementById("anonymous_sales_check_approved_crypto_price_cents");
var routerUrl=(window.AnonymousSalesAdmin&&window.AnonymousSalesAdmin.routerUrl)||"";
if(!keyRotationCheckbox){return;}
keyRotationCheckbox.addEventListener("change",function(){
if(!keyRotationCheckbox.checked){return;}
window.alert("Router key rotation is recommended only when there are no unfinished orders.");
});
if(approvedCryptoBtn&&approvedCryptoResult&&routerUrl){
approvedCryptoBtn.addEventListener("click",function(){
approvedCryptoBtn.disabled=true;
approvedCryptoResult.textContent="Checking approved crypto...";
var params=new URLSearchParams();
params.set("action","check_approved_crypto");
if(approvedCryptoPriceCents&&approvedCryptoPriceCents.value){var cents=parseInt(approvedCryptoPriceCents.value,10)||0;if(cents>0){params.set("include_minimums","1");params.set("price_currency","usd");params.set("price_amount",String(cents/100));}}
fetch(routerUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:params.toString()})
.then(function(r){return r.json();})
.then(function(data){
if(!data||!data.success){approvedCryptoResult.textContent=(data&&data.message)?data.message:"Could not fetch approved crypto list.";return;}
var coins=Array.isArray(data.approved_coins)?data.approved_coins:[];
if(!coins.length){approvedCryptoResult.textContent="No approved crypto returned by NOWPayments.";return;}
var text="Approved crypto ("+coins.length+"):\n"+coins.join(", ");
var eligible=Array.isArray(data.eligible_coins)?data.eligible_coins:[];
var ineligible=Array.isArray(data.ineligible_coins)?data.ineligible_coins:[];
if(approvedCryptoPriceCents&&approvedCryptoPriceCents.value){text+="\n\nEligible for provided price ("+eligible.length+"):\n"+(eligible.length?eligible.join(", "):"none");if(ineligible.length){text+="\n\nBelow minimum ("+ineligible.length+"):\n"+ineligible.join(", ");}}
approvedCryptoResult.textContent=text;
})
.catch(function(){approvedCryptoResult.textContent="Network error while checking approved crypto.";})
.finally(function(){approvedCryptoBtn.disabled=false;});
});
}
})();

// Error log screen: filters, refresh, copy, clear
(function(){
var ajaxUrl=window.AnonymousSalesAdmin.ajaxUrl;
var refreshNonce=window.AnonymousSalesAdmin.errorLogNonce||"";
var levelFilter=document.getElementById("anonymous_sales_log_level_filter");
var searchInput=document.getElementById("anonymous_sales_log_search");
var rows=Array.prototype.slice.call(document.querySelectorAll(".anonymous-sales-log-row"));
var rowsContainer=document.getElementById("anonymous_sales_log_rows");
var countLabel=document.getElementById("anonymous_sales_log_count");
var selectVisibleBtn=document.getElementById("anonymous_sales_log_select_visible");
var clearSelectionBtn=document.getElementById("anonymous_sales_log_clear_selection");
var refreshBtn=document.getElementById("anonymous_sales_log_refresh");
var copySelectedBtn=document.getElementById("anonymous_sales_log_copy_selected");
var copyAllBtn=document.getElementById("anonymous_sales_log_copy_all");
var clearBtn=document.getElementById("anonymous_sales_clear_error_log_btn");
var escapeHtml=function(text){
return String(text||"").replace(/[&<>"']/g,function(char){return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"})[char];});
};
var badgeStyleByLevel=function(level){
var key=String(level||"UNKNOWN").toUpperCase();
if(key==="ERROR"){return "background:#fbeaea;color:#8b0000;border:1px solid #f1b5b5;";}
if(key==="WARNING"){return "background:#fff7e6;color:#8a5800;border:1px solid #f0d195;";}
if(key==="INFO"){return "background:#eaf4ff;color:#0b4f8a;border:1px solid #b8d8f5;";}
if(key==="DEBUG"){return "background:#f2f2f4;color:#4d4d57;border:1px solid #d4d4dc;";}
return "background:#f7f7f7;color:#555;border:1px solid #dcdcde;";
};
var refreshRowsCache=function(){rows=Array.prototype.slice.call(document.querySelectorAll(".anonymous-sales-log-row"));};
var renderRows=function(entries){
if(!rowsContainer){return;}
if(!entries||!entries.length){rowsContainer.innerHTML="";refreshRowsCache();applyFilters();return;}
var html="";
entries.forEach(function(entry,index){
var level=String((entry&&entry.level)||"UNKNOWN").toUpperCase();
var line=String((entry&&entry.line)||"");
html+="<tr class=\"anonymous-sales-log-row\" data-level=\""+escapeHtml(level)+"\" data-line=\""+escapeHtml(line)+"\">";
html+="<td><label style=\"display:flex;gap:6px;align-items:center;\"><input type=\"checkbox\" class=\"anonymous-sales-log-select\" /><span>"+String(index+1)+"</span></label></td>";
html+="<td><span style=\"display:inline-block;min-width:72px;text-align:center;font-weight:700;border-radius:999px;padding:2px 8px;"+badgeStyleByLevel(level)+"\">"+escapeHtml(level)+"</span></td>";
html+="<td><code style=\"display:block;white-space:pre-wrap;word-break:break-word;color:#8b0000;\">"+escapeHtml(line)+"</code></td></tr>";
});
rowsContainer.innerHTML=html;
refreshRowsCache();
applyFilters();
};
var copyText=function(text){
if(!text){window.alert("There is no log content to copy.");return;}
if(navigator.clipboard&&navigator.clipboard.writeText){
navigator.clipboard.writeText(text).then(function(){window.alert("Copied to clipboard.");}).catch(function(){fallbackCopy(text);});
return;
}
fallbackCopy(text);
};
var fallbackCopy=function(text){
var helper=document.createElement("textarea");
helper.value=text;
helper.setAttribute("readonly","readonly");
helper.style.position="fixed";helper.style.opacity="0";helper.style.pointerEvents="none";
document.body.appendChild(helper);
helper.select();
var ok=false;
try{ok=document.execCommand("copy");}catch(e){ok=false;}
document.body.removeChild(helper);
window.alert(ok?"Copied to clipboard.":"Copy failed. Please copy manually.");
};
var getVisibleRows=function(){return rows.filter(function(row){return row.style.display!=="none";});};
var applyFilters=function(){
var level=(levelFilter&&levelFilter.value)?levelFilter.value:"ALL";
var query=((searchInput&&searchInput.value)||"").trim().toLowerCase();
var visible=0;
rows.forEach(function(row){
var rowLevel=(row.getAttribute("data-level")||"UNKNOWN").toUpperCase();
var line=(row.getAttribute("data-line")||"").toLowerCase();
var levelMatch=level==="ALL"||rowLevel===level;
var queryMatch=query===""||line.indexOf(query)!==-1;
var show=levelMatch&&queryMatch;
row.style.display=show?"":"none";
if(show){visible++;}
});
if(countLabel){countLabel.textContent="Visible: "+visible+" / "+rows.length;}
};
if(levelFilter){levelFilter.addEventListener("change",applyFilters);}
if(searchInput){searchInput.addEventListener("input",applyFilters);}
if(selectVisibleBtn){selectVisibleBtn.addEventListener("click",function(){getVisibleRows().forEach(function(row){var box=row.querySelector(".anonymous-sales-log-select");if(box){box.checked=true;}});});}
if(clearSelectionBtn){clearSelectionBtn.addEventListener("click",function(){rows.forEach(function(row){var box=row.querySelector(".anonymous-sales-log-select");if(box){box.checked=false;}});});}
if(refreshBtn){refreshBtn.addEventListener("click",function(){
refreshBtn.disabled=true;
refreshBtn.textContent="Refreshing...";
var body=new URLSearchParams();
body.append("action","anonymous_sales_refresh_error_log");
body.append("nonce",refreshNonce);
fetch(ajaxUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()})
.then(function(response){return response.json();})
.then(function(payload){
if(!payload||payload.success!==true){throw new Error("Refresh failed");}
renderRows(Array.isArray(payload.data&&payload.data.entries)?payload.data.entries:[]);
})
.catch(function(){window.alert("Could not refresh log preview.");})
.finally(function(){refreshBtn.disabled=false;refreshBtn.textContent="Refresh";});
});}
if(copySelectedBtn){copySelectedBtn.addEventListener("click",function(){
var selectedLines=[];
rows.forEach(function(row){var box=row.querySelector(".anonymous-sales-log-select");if(box&&box.checked){selectedLines.push(row.getAttribute("data-line")||"");}});
copyText(selectedLines.join("\n"));
});}
if(copyAllBtn){copyAllBtn.addEventListener("click",function(){
var allLines=rows.map(function(row){return row.getAttribute("data-line")||"";});
copyText(allLines.join("\n"));
});}
if(clearBtn){clearBtn.addEventListener("click",function(event){
var confirmed=window.confirm("Clearing the error log is not recommended. Are you sure you want to clear it?");
if(!confirmed){event.preventDefault();}
});}
applyFilters();
})();
