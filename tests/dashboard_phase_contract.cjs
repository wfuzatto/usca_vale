'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const html=fs.readFileSync('index.php','utf8');
const visual=fs.readFileSync('assets/visual-dashboard.js','utf8');
const history=fs.readFileSync('assets/app.js','utf8');
const api=fs.readFileSync('api.php','utf8');
assert(html.includes('id="phaseMeasurements"'),'phase table container missing');
assert(html.includes('assets/chart-series.js'),'cadence helper must be loaded before rendering');
for(const field of ['mains_voltage_l12','mains_voltage_l23','mains_voltage_l31','bus_voltage_l12','bus_voltage_l23','bus_voltage_l31','voltage_l1n','voltage_l2n','voltage_l3n','current_l1','current_l2','current_l3']){
  assert(visual.includes(field),field+' metric omitted from phase display');
}
assert(visual.includes('phaseMeasurements(data)'), 'phase display not refreshed');
assert(visual.includes('USCAChartSeries.joins'), 'live chart must use polling cadence');
assert(history.includes('USCAChartSeries.joins'), 'history chart must use polling cadence');
assert(api.includes("'poll_seconds'=>$pollSeconds"), 'history API missing poll cadence');
assert(!visual.includes("if(prior!==null&&ts[i]-prior<=bucket*2600"),'regressed to bucket-only cadence');
console.log('OK: phase display uses measured M, BB and L1/L2/L3; chart continuity uses polling cadence.');
