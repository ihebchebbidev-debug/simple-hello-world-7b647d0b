import { HTMLAttributes } from 'react';

/**
 * Tonal pulse block for loading states.
 * Uses surface tokens so it works in both light and dark themes.
 */
export const Skeleton = ({ className = '', style, ...rest }: HTMLAttributes<HTMLDivElement>) => (
  <div
    className={`animate-pulse rounded-md bg-[hsl(var(--surface-bright)/0.5)] ${className}`}
    style={style}
    {...rest}
  />
);

export default Skeleton;
