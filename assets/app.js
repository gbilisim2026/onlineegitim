document.addEventListener('click',function(event){
  const open=event.target.closest('[data-modal]');
  if(open){const modal=document.getElementById(open.dataset.modal);if(modal)modal.classList.add('open');}
  if(event.target.matches('[data-close]'))event.target.closest('.modal').classList.remove('open');
  if(event.target.classList.contains('modal'))event.target.classList.remove('open');
  const edit=event.target.closest('[data-edit-class]');
  if(edit){
    const data=JSON.parse(edit.dataset.editClass);
    const modal=document.getElementById('class-modal');
    modal.querySelector('h2').textContent='Sınıfı düzenle';
    modal.querySelector('[name="id"]').value=data.id;
    modal.querySelector('[name="name"]').value=data.name;
    modal.querySelector('[name="track"]').value=data.track;
    modal.querySelector('[name="grade"]').value=data.grade;
    modal.querySelector('[name="description"]').value=data.description||'';
    modal.classList.add('open');
  }
  const add=event.target.closest('[data-add-question]');
  if(add){
    const list=document.querySelector('[data-question-list]');
    const i=list.children.length;
    const wrap=document.createElement('div');
    wrap.className='question-editor';
    wrap.innerHTML='<button type="button" data-remove-question aria-label="Soruyu sil">×</button><label>Soru '+(i+1)+'<input name="question['+i+']" required placeholder="Sorunuzu yazın"></label><div class="form-row">'+['A','B','C','D'].map((letter,n)=>'<label>Seçenek '+letter+'<input name="options['+i+'][]" required></label>').join('')+'</div><label>Doğru cevap<select name="correct['+i+']"><option value="0">A</option><option value="1">B</option><option value="2">C</option><option value="3">D</option></select></label>';
    list.appendChild(wrap);
  }
  if(event.target.matches('[data-remove-question]'))event.target.closest('.question-editor').remove();
  const confirmButton=event.target.closest('[data-confirm]');
  if(confirmButton&&!window.confirm(confirmButton.dataset.confirm))event.preventDefault();
});
document.addEventListener('input',function(event){
  if(event.target.matches('[data-table-search]')){
    const query=event.target.value.toLocaleLowerCase('tr');
    document.querySelectorAll('[data-search-table] tbody tr').forEach(row=>row.hidden=!row.innerText.toLocaleLowerCase('tr').includes(query));
  }
});
function updateUserAssignments(form){
  const role=form.querySelector('[data-user-role]').value;
  const classSelect=form.querySelector('[data-class-assignments]');
  const courseLabel=form.querySelector('[data-course-assignment]');
  const courseSelect=form.querySelector('[data-course-assignments]');
  if(role==='admin')Array.from(classSelect.options).forEach(option=>option.selected=false);
  const selectedClasses=new Set(Array.from(classSelect.selectedOptions).map(option=>option.value));
  classSelect.required=role!=='admin';
  courseLabel.hidden=role!=='student';
  courseLabel.style.display=role==='student'?'':'none';
  courseSelect.disabled=role!=='student';
  Array.from(courseSelect.options).forEach(option=>{
    const visible=selectedClasses.has(option.dataset.classId);
    option.hidden=!visible;
    if(!visible)option.selected=false;
  });
}
document.querySelectorAll('[data-user-form]').forEach(form=>{
  updateUserAssignments(form);
  form.addEventListener('change',event=>{
    if(event.target.matches('[data-user-role],[data-class-assignments]'))updateUserAssignments(form);
  });
});
document.addEventListener('click',function(event){
  if(event.target.matches('.toggle-password')){
    const input=event.target.parentElement.querySelector('input');
    input.type=input.type==='password'?'text':'password';
  }
});
