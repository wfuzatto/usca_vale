'use strict';
const assert=require('node:assert/strict');
const s=require('../assets/chart-series.js');
// 5-minute window: SQL bucket 1 second, acquisition every 5 seconds.
assert.equal(s.limitMs(1,5),12500);
assert.equal(s.joins(100000,105000,1,5),true,'5-second samples must connect');
assert.equal(s.joins(100000,110000,1,5),true);
assert.equal(s.joins(100000,131000,1,5),false,'31-second loss must leave a gap');
assert.equal(s.joins(100000,100000,1,5),false,'repeated timestamps not a line');
assert.equal(s.joins(NaN,105000,1,5),false);
assert.equal(s.joins(100000,110000,8,5),true,'8-second bucket stays connected');
assert.equal(s.joins(100000,160000,8,5),false);
assert.equal(s.joins(100000,125000,10,5),true,'10-second configured bucket');
console.log('OK: chart lines connect 5-second readings and preserve communication gaps.');
