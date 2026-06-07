import { useEffect, useMemo, useState } from 'react';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';
import PasswordInput from '@/components/PasswordInput';
import {
  ROLE_OPTIONS,
  type AdminUser,
  type AppRole,
  normaliseRoles,
} from './types';

const buildSchemas = (t: (k: string) => string) => {
  const base = z.object({
    name: z.string().trim().min(2, t('validation.nameMin2')).max(120, t('validation.tooLarge')),
    email: z.string().trim().toLowerCase().email(t('validation.emailInvalid')).max(180, t('validation.tooLarge')),
    preferred_lang: z.enum(['fr', 'en']),
    roles: z.array(z.enum(['admin', 'manager', 'technician'])).min(1, t('validation.rolesMin1')).max(3),
    is_active: z.boolean(),
  });
  return {
    create: base.extend({
      password: z.string().min(8, t('validation.passwordMin8')).max(200, t('validation.tooLarge')),
    }),
    edit: base.extend({
      password: z
        .union([z.string().min(8, t('validation.passwordMin8')).max(200, t('validation.tooLarge')), z.literal('')])
        .optional(),
    }),
  };
};

export interface UserFormValues {
  name: string;
  email: string;
  password: string;
  preferred_lang: 'fr' | 'en';
  roles: AppRole[];
  is_active: boolean;
}

export interface UserFormSubmit {
  name: string;
  email: string;
  preferred_lang: 'fr' | 'en';
  roles: AppRole[];
  is_active: boolean;
  password?: string;
}

interface UserFormModalProps {
  open: boolean;
  mode: 'create' | 'edit';
  initial?: AdminUser | null;
  submitting?: boolean;
  serverError?: string | null;
  onClose: () => void;
  onSubmit: (values: UserFormSubmit) => void;
}

const emptyValues: UserFormValues = {
  name: '',
  email: '',
  password: '',
  preferred_lang: 'fr',
  roles: ['technician'],
  is_active: true,
};

const UserFormModal = ({
  open,
  mode,
  initial,
  submitting = false,
  serverError = null,
  onClose,
  onSubmit,
}: UserFormModalProps) => {
  const { t } = useTranslation();
  const roleLabel = (r: AppRole) => t(`users.role.${r}`);
  const [values, setValues] = useState<UserFormValues>(emptyValues);
  const [errors, setErrors] = useState<Partial<Record<keyof UserFormValues, string>>>({});

  useEffect(() => {
    if (!open) return;
    if (initial) {
      setValues({
        name: initial.name,
        email: initial.email,
        password: '',
        preferred_lang: initial.preferred_lang ?? 'fr',
        roles: normaliseRoles(initial.roles),
        is_active: initial.is_active,
      });
    } else {
      setValues(emptyValues);
    }
    setErrors({});
  }, [open, initial]);

  const schema = useMemo(() => {
    const s = buildSchemas(t);
    return mode === 'create' ? s.create : s.edit;
  }, [mode, t]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    const result = schema.safeParse(values);
    if (!result.success) {
      const fieldErrors: Partial<Record<keyof UserFormValues, string>> = {};
      for (const issue of result.error.issues) {
        const k = issue.path[0] as keyof UserFormValues;
        if (k && !fieldErrors[k]) fieldErrors[k] = issue.message;
      }
      setErrors(fieldErrors);
      return;
    }
    const payload: UserFormSubmit = {
      name: result.data.name,
      email: result.data.email,
      preferred_lang: result.data.preferred_lang,
      roles: result.data.roles,
      is_active: result.data.is_active,
    };
    if (mode === 'create') {
      payload.password = (result.data as UserFormValues).password;
    } else if (values.password && values.password.length > 0) {
      payload.password = values.password;
    }
    onSubmit(payload);
  };

  const toggleRole = (role: AppRole) => {
    setValues((v) => ({
      ...v,
      roles: v.roles.includes(role) ? v.roles.filter((r) => r !== role) : [...v.roles, role],
    }));
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={mode === 'create' ? t('users.formCreateTitle') : t('users.formEditTitle')}
      description={mode === 'create' ? t('users.formCreateDesc') : undefined}
      size="md"
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            {t('common.cancel')}
          </button>
          <button
            type="submit"
            form="user-form"
            disabled={submitting}
            className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
          >
            {submitting ? t('common.saving') : t('common.save')}
          </button>
        </>
      }
    >
      <form id="user-form" onSubmit={handleSubmit} className="space-y-3">
        <Field label={t('users.name')} error={errors.name}>
          <input
            type="text"
            value={values.name}
            maxLength={120}
            onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
          />
        </Field>

        <Field label={t('users.email')} error={errors.email}>
          <input
            type="email"
            value={values.email}
            maxLength={180}
            onChange={(e) => setValues((v) => ({ ...v, email: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
          />
        </Field>

        <Field
          label={mode === 'create' ? t('users.password') : t('users.passwordEdit')}
          error={errors.password}
        >
          <PasswordInput
            value={values.password}
            maxLength={200}
            autoComplete="new-password"
            placeholder={mode === 'edit' ? t('users.passwordEditPlaceholder') : '••••••••'}
            onChange={(e) => setValues((v) => ({ ...v, password: e.target.value }))}
            inputClassName="h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
          />
        </Field>

        <Field label={t('users.roles')} error={errors.roles}>
          <div className="flex flex-wrap gap-2">
            {ROLE_OPTIONS.map((role) => {
              const active = values.roles.includes(role);
              return (
                <button
                  key={role}
                  type="button"
                  onClick={() => toggleRole(role)}
                  className={
                    active
                      ? 'rounded-full border border-primary bg-primary/15 px-3 py-1 text-xs font-medium text-primary'
                      : 'rounded-full border border-border bg-background px-3 py-1 text-xs text-muted-foreground hover:bg-muted'
                  }
                >
                  {roleLabel(role)}
                </button>
              );
            })}
          </div>
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label={t('common.language')}>
            <select
              value={values.preferred_lang}
              onChange={(e) =>
                setValues((v) => ({ ...v, preferred_lang: e.target.value as 'fr' | 'en' }))
              }
              className="h-9 w-full rounded-md border border-border bg-background px-2 text-sm"
            >
              <option value="fr">{t('common.french')}</option>
              <option value="en">{t('common.english')}</option>
            </select>
          </Field>
          <Field label={t('common.status')}>
            <select
              value={values.is_active ? 'active' : 'inactive'}
              onChange={(e) =>
                setValues((v) => ({ ...v, is_active: e.target.value === 'active' }))
              }
              className="h-9 w-full rounded-md border border-border bg-background px-2 text-sm"
            >
              <option value="active">{t('common.active')}</option>
              <option value="inactive">{t('common.inactive')}</option>
            </select>
          </Field>
        </div>

        {serverError && (
          <p className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300">
            {serverError}
          </p>
        )}
      </form>
    </Modal>
  );
};

interface FieldProps {
  label: string;
  error?: string;
  children: React.ReactNode;
}

const Field = ({ label, error, children }: FieldProps) => (
  <label className="block space-y-1">
    <span className="text-xs font-medium text-muted-foreground">{label}</span>
    {children}
    {error && <span className="block text-[11px] text-rose-400">{error}</span>}
  </label>
);

export default UserFormModal;
