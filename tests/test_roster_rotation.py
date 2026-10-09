"""Exercise the actual roster API block without database/auth bootstrap."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'api.php').read_text(encoding='utf-8-sig')
BLOCK = SOURCE[SOURCE.index("if ($action === 'calculate_roster') {"):SOURCE.index('// VPBX / REGULATORY TESTING PORTAL ACTIONS')]


def roster(**overrides):
    params = dict(anchor='2026-10-15', range_start='2026-10-15', range_end='2026-10-30',
                  check_date='2026-10-19', check_time='06:59', days_on=4, days_off=4,
                  rotation='alternating', starting_shift='night')
    params.update(overrides)
    import base64
    encoded = base64.b64encode(json.dumps(params).encode()).decode()
    source = "<?php $_POST = json_decode(base64_decode('" + encoded + "'), true); $action = 'calculate_roster';\n" + BLOCK
    result = subprocess.run(['php'], input=source, text=True, capture_output=True, cwd=ROOT, check=True)
    return json.loads(result.stdout.lstrip('\ufeff'))


class RotationTests(unittest.TestCase):
    def test_full_cycle(self):
        data = roster()
        self.assertTrue(data['success'])
        self.assertEqual([r['shift_name'] for r in data['daily']], ['Night'] * 4 + ['Off'] * 4 + ['Morning'] * 4 + ['Off'] * 4)
        self.assertEqual(data['daily'][3]['shift_end'], '07:00 (+1 day)')
        self.assertEqual(data['kpis']['total_hours'], 96)
        self.assertEqual(data['kpis']['completed_duty'], 3)

    def test_night_end_boundary(self):
        self.assertTrue(roster()['monitor']['is_active'])
        ended = roster(check_time='07:00')
        self.assertFalse(ended['monitor']['is_active'])
        self.assertEqual(ended['kpis']['completed_duty'], 4)

    def test_start_boundary(self):
        self.assertTrue(roster(check_date='2026-10-15', check_time='18:59')['monitor']['is_upcoming'])
        self.assertTrue(roster(check_date='2026-10-15', check_time='19:00')['monitor']['is_active'])

    def test_before_anchor_and_next_cycle(self):
        data = roster(range_start='2026-10-07', range_end='2026-10-31')['daily']
        self.assertEqual(data[0]['shift_name'], 'Morning')
        self.assertEqual(data[-1]['shift_name'], 'Night')

    def test_editable_anchor_and_starting_shift(self):
        data = roster(anchor='2026-12-28', range_start='2026-12-28', range_end='2027-01-12', starting_shift='day')['daily']
        self.assertEqual([r['shift_name'] for r in data], ['Morning'] * 4 + ['Off'] * 4 + ['Night'] * 4 + ['Off'] * 4)

    def test_fixed_shift_compatibility(self):
        data = roster(rotation='fixed', shift_start='09:00', shift_hours=8)['daily']
        self.assertEqual(data[0]['shift_start'], '09:00')
        self.assertEqual(data[8]['shift_start'], '09:00')
        self.assertEqual(data[8]['hours'], 8)

    def test_invalid_date(self):
        self.assertFalse(roster(anchor='2026-02-30')['success'])
        self.assertFalse(roster(range_end='2026-10-01')['success'])


if __name__ == '__main__':
    unittest.main()
