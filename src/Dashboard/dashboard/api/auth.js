import { requestJson } from "./http.js";

/**
 * Um bilhete de vida curta e uso único para abrir um stream.
 *
 * O `EventSource` não deixa pôr cabeçalhos, e por isso a credencial tem de ir no URL -- que
 * fica escrito no registo de acessos de qualquer proxy pelo caminho e no histórico do
 * browser. O que ali ia era o token de acesso, bom durante uma hora e para a API toda; passa
 * a ir isto, que vale segundos e uma ligação.
 */
export const getStreamTicket = () => requestJson("/api/auth/stream-ticket", { method: "POST" });
