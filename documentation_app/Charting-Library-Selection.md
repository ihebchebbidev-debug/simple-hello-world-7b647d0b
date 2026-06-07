# Charting Library Selection

Recharts is the primary library for the Agri-Sync Admin Dashboard. It is selected for its ease of management and the granular control it provides over both data mapping and visual styling.

---

## 1. Ease of Management
Recharts uses a component-based architecture that makes the dashboard highly maintainable:

*   **JSX-Based Editing**: All chart elements (Axes, Tooltips, Legends, Grids) are standard React components. Editing a chart's layout or behavior is as simple as updating props in the code.
*   **Minimal Configuration**: Unlike other libraries that require hundreds of lines of nested JSON configuration, Recharts logic is split across small, readable components.
*   **Rapid Iteration**: Changing a chart from a Bar chart to a Line chart or adjusting the "Radius" of bars takes seconds, allowing for fast UI adjustments based on manager feedback.

---

## 2. Granular Data Control
Agri-Sync requires precise mapping of agricultural data (e.g., water volume and nutrient units). Recharts provides perfect control over this flow:

*   **Plain Data Mapping**: The library accepts a simple array of objects. There is no proprietary data format; we pass the exact JSON structure returned by the Laravel API.
*   **Custom Tooltips**: We have full control over the `Tooltip` content, allowing us to display complex units (like "m³ / hectare") or calculate derived values on the fly without complex library-specific APIs.
*   **Scalability**: Adding new data points or switching between "Monthly" and "Cumulative" views is handled through standard React state management.

---

## 3. Implementation Pattern
Data flows directly from Refine's hooks into the chart, ensuring the UI always reflects the current filtered state.

```jsx
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';

const IrrigationChart = ({ data }) => (
  <ResponsiveContainer width="100%" height={300}>
    <BarChart data={data}>
      <CartesianGrid strokeDasharray="3 3" vertical={false} />
      <XAxis dataKey="month" />
      <YAxis label={{ value: 'm³ / ha', angle: -90, position: 'insideLeft' }} />
      <Tooltip cursor={{fill: 'transparent'}} />
      {/* Visual elements are edited directly via props */}
      <Bar dataKey="quantity" fill="#3b82f6" radius={[4, 4, 0, 0]} />
    </BarChart>
  </ResponsiveContainer>
);
```

---

## 4. Selection Rationale
The choice of Recharts prioritises **Developer Velocity** and **Total Logic Control**. By using a library that speaks the language of React, we ensure that the charting layer is never a bottleneck for future feature requests or data refinements.
