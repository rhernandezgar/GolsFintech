/**
 * Los siete pasos del recorrido de la Fase 2, tal como se muestran en el
 * indicador. La bifurcacion manual/OCR se pinta como dos pasos que
 * convergen antes de la validacion de identidad.
 */
export const STEPS = [
  { key: 'welcome', label: 'Inicio', path: '/' },
  { key: 'prospect-data', label: 'Datos', path: '/datos' },
  { key: 'document-upload', label: 'Identificacion', path: '/identificacion' },
  { key: 'verification', label: 'Verificacion', path: '/verificacion' },
  { key: 'simulation', label: 'Simulacion', path: '/simulacion' },
  { key: 'authorization', label: 'Autorizacion', path: '/autorizacion' },
]

export function stepIndexFor(routeName) {
  const map = {
    WelcomeView: 0,
    ProspectDataFormView: 1,
    DocumentUploadView: 2,
    VerificationResultView: 3,
    CreditSimulationView: 4,
    AuthorizationConfirmedView: 5,
  }
  return map[routeName] ?? -1
}
