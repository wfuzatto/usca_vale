'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
class Node {
  constructor(name){this.tagName=name;this.children=[];this.listeners={};this.style={};this.classList={add(){}};this.hidden=false;this.offsetWidth=200;this.offsetHeight=100;this.textContent='';}
  append(...children){this.children.push(...children)}
  replaceChildren(...children){this.children=[...children]}
  addEventListener(name,fn){this.listeners[name]=fn}
  setAttribute(){}
}
const host=new Node('div');
host.getBoundingClientRect=()=>({left:0,top:0,width:300,height:180});
const canvas=new Node('canvas');
canvas.parentElement=host;canvas.clientWidth=300;canvas.clientHeight=140;
canvas.getBoundingClientRect=()=>({left:0,top:0,width:300,height:140});
const ctx={window:{},document:{createElement(name){return new Node(name)}},Intl,WeakMap,Date,Number,Math};
vm.runInNewContext(fs.readFileSync('assets/chart-hover.js','utf8'),ctx,{filename:'assets/chart-hover.js'});
assert.equal(typeof ctx.window.USCAChartHover.attach,'function');
const ts=Date.parse('2026-10-28T21:20:00Z');
const row={ts,current_l1:20.5,current_l2:0,current_l3:null};
const series=[{key:'current_l1',label:'Corrente L1',unit:'A',color:'#147df0'},
              {key:'current_l2',label:'Corrente L2',unit:'A',color:'#12b957'},
              {key:'current_l3',label:'Corrente L3',unit:'A',color:'#ed3449'}];
ctx.window.USCAChartHover.attach(canvas,{
  rows:[row],series,plot:{left:20,top:10,width:250,height:110},x(){return 120},y(n){return 100-n}
});
canvas.listeners.mousemove({clientX:120,clientY:79.5});
const tip=host.children[2];
assert.equal(tip.hidden,false);
assert.match(tip.children[0].textContent,/28\/10\/2026/);
assert.equal(tip.children[2].children.length,2,'null must be omitted, 0 must be retained');
assert.match(tip.children[2].children[0].children[1].textContent,/20,5 A/);
assert.match(tip.children[2].children[1].children[1].textContent,/0,0 A/);
canvas.listeners.mousemove({clientX:20,clientY:130});
assert.equal(tip.hidden,true,'Tooltip must be hidden far from an actual plotted point');
ctx.window.USCAChartHover.attach(canvas,{rows:[],series:[]});
assert.equal(tip.hidden,true,'No stale tooltip after a redraw with no data');
console.log('OK: hover com horario, valores reais, zero valido, nulos e limites de plot.');
