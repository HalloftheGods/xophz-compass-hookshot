/**
 * Hookshot Test Runner
 */

import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';
import './verify-mode.test.mjs';

const results = await harness.run();
if (results.failed > 0) {
  for (const f of results.failures) {
    console.error(`FAIL ${f.suiteName} > ${f.testName}\n${f.error.split('\n').slice(0, 3).join('\n')}`);
  }
  console.error(`Hookshot Tests FAILED: ${results.failed} failed, ${results.passed} passed.`);
  process.exit(1);
} else {
  console.log(`Hookshot Tests PASSED: ${results.passed} passed.`);
  process.exit(0);
}
