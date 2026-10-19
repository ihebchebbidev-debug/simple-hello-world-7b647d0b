import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { act } from 'react-dom/test-utils';
import { describe, expect, it } from 'vitest';
import '@/i18n';
import FertilizationFields from './FertilizationFields';

describe('FertilizationFields', () => {
  it('allows large fertilizer quantities without showing a hard cap error', () => {
    const container = document.createElement('div');
    document.body.appendChild(container);

    let root: Root;
    act(() => {
      root = createRoot(container);
      root.render(
        <FertilizationFields
          items={[{ id: '1', fertilizer_id: 'f-1', quantity: '12000' }]}
          onChange={() => undefined}
          fertilizers={[{ id: 'f-1', name: 'Engrais', unit: 'kg' } as any]}
          waterM3=""
          onWaterChange={() => undefined}
        />,
      );
    });

    const input = container.querySelector('input[type="number"]') as HTMLInputElement | null;
    expect(input).not.toBeNull();
    expect(input?.value).toBe('12000');
    expect(container.textContent).not.toContain('maximum');

    act(() => {
      root!.unmount();
    });
    container.remove();
  });
});
