import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
function component(send) {
  const source=readFileSync(new URL('../resources/js/pages/FileExplorer.vue',import.meta.url),'utf8').match(/<script>([\s\S]*?)<\/script>/)[1].replace('export default','return')
  const options=new Function('Nova',source)({request:()=>({get:send})})
  const vm=options.data();Object.assign(vm,Object.fromEntries(Object.entries(options.methods).map(([key,value])=>[key,value.bind(vm)])))
  return {vm,options}
}
test('empty partial pages can continue and previous restores cursors',async()=>{
  const {vm}=component(async()=>({data:{data:[],snapshot:'file',next_cursor:'next'}}));
  await vm.refresh();await vm.next();assert.equal(vm.cursors.length,2);await vm.previous();assert.equal(vm.cursors.length,1)
})
test('stale responses cannot replace new filters and close cancels detail',async()=>{
  let resolve;
  const {vm}=component(()=>new Promise(r=>{resolve=r}));
  const pending=vm.load(null);vm.version++;resolve({data:{data:[{message:'obsolete'}]}});await pending;assert.equal(vm.rows.length,0)
  const detail=vm.show({reference:'ref',locator:'loc'});vm.close();resolve({data:{text:'obsolete'}});await detail;assert.equal(vm.selected,null);assert.deepEqual(vm.detail,{})
})
test('failed reads release loading and preserve the existing list',async()=>{
  const {vm}=component(async()=>{throw {response:{data:{message:'Rotated'}}}});vm.rows=[{message:'preserved'}];await vm.load(null);assert.equal(vm.loading,false);assert.equal(vm.error,'Rotated');assert.equal(vm.rows[0].message,'preserved')
})
